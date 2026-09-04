<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Finance\Services\InvoiceManager;
use App\Domain\Finance\Services\PaymentManager;
use App\Domain\Licensing\Services\LicenseService;
use App\Domain\Security\Contracts\FileScanner;
use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StorePaymentRequest;
use App\Http\Requests\Customer\StoreServiceRequest;
use App\Http\Requests\Customer\TransitionServiceRequest;
use App\Http\Requests\Customer\UpdateServiceRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LicenseActivation;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PortalController extends Controller
{
    public function services(Request $request, LicenseService $licenses): Response
    {
        $ids = $this->customerIds($request);
        $services = Subscription::query()->whereIn('customer_id', $ids)
            ->with(['customer:id,uuid,name', 'product:id,uuid,name,code,slug,logo', 'plan:id,uuid,name', 'invoice:id,uuid,invoice_number,status,total,balance,currency,due_date', 'license'])
            ->latest()->get();

        return Inertia::render('Customer/Services/Index', ['services' => $services->map(fn (Subscription $item) => [
            'uuid' => $item->uuid, 'number' => $item->subscription_number, 'status' => $item->status->value,
            'customer' => $item->customer->name, 'product' => $item->product?->name, 'product_slug' => $item->product?->slug,
            'plan' => $item->plan?->name, 'price' => $item->price, 'currency' => $item->currency,
            'billing_cycle' => $item->billing_cycle->value, 'starts_at' => $item->starts_at?->toDateString(),
            'ends_at' => $item->ends_at?->toDateString(), 'auto_renew' => $item->auto_renew,
            'client_setup' => $this->clientSetupData($item, $licenses),
            'invoice' => $item->invoice ? ['uuid' => $item->invoice->uuid, 'number' => $item->invoice->invoice_number, 'status' => $item->invoice->status->value, 'total' => $item->invoice->total, 'balance' => $item->invoice->balance, 'currency' => $item->invoice->currency, 'due_date' => $item->invoice->due_date->toDateString()] : null,
        ])]);
    }

    public function createService(Request $request): Response
    {
        $plans = Plan::query()->publiclyAvailable()->whereNotNull('price')->with(['product:id,uuid,name,code', 'features', 'limits'])->get();

        return Inertia::render('Customer/Services/Create', [
            'customer' => $this->primaryCustomer($request)->only(['uuid', 'name', 'customer_number']),
            'products' => $plans->pluck('product')->filter()->unique('uuid')->values()->map->only(['uuid', 'name', 'code']),
            'plans' => $plans->map(fn (Plan $plan) => $this->planData($plan)),
        ]);
    }

    public function storeService(StoreServiceRequest $request, SubscriptionService $service, InvoiceManager $invoices): RedirectResponse
    {
        $customer = $this->primaryCustomer($request);
        $plan = Plan::query()->publiclyAvailable()->whereNotNull('price')->with('product')->where('uuid', $request->validated('plan_uuid'))
            ->whereHas('product', fn ($query) => $query->where('uuid', $request->validated('product_uuid')))->firstOrFail();
        $duplicate = Subscription::query()->where('customer_id', $customer->id)->where('product_id', $plan->product_id)
            ->whereIn('status', ['pending', 'trial', 'active', 'grace', 'suspended'])->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['plan_uuid' => 'This account already has a current service for that product. Manage the existing service instead.']);
        }
        [$subscription, $invoice] = DB::transaction(function () use ($customer, $plan, $request, $service, $invoices): array {
            $invoice = $invoices->create([
                'customer_id' => $customer->id, 'issue_date' => today(), 'due_date' => today()->addDays(7), 'currency' => $plan->currency,
                'notes' => 'Automatically generated for a new '.$plan->product->name.' service.', 'terms' => 'Service activation occurs after full payment is verified.',
                'items' => [['product_id' => $plan->product_id, 'plan_id' => $plan->id, 'description' => $plan->product->name.' — '.$plan->name.' plan', 'quantity' => 1, 'unit_price' => $plan->price, 'discount' => 0, 'tax' => 0]],
            ], $request->user());

            return [$service->create($customer, $plan, $invoice, $request->user(), ['auto_renew' => $request->boolean('auto_renew')]), $invoice];
        });
        $invoices->issue($invoice, $request->user());

        return redirect()->route('portal.invoices.show', $invoice)->with('success', 'Service and invoice created. Pay online to activate your service.');
    }

    public function editService(Request $request, Subscription $subscription): Response
    {
        $this->ownedSubscription($request, $subscription);
        $subscription->load(['customer:id,name', 'product:id,name,code', 'plan:id,uuid,name']);
        $plans = Plan::query()->publiclyAvailable()->where('product_id', $subscription->product_id)->whereNotNull('price')->with(['product:id,uuid,name,code', 'features', 'limits'])->get();

        return Inertia::render('Customer/Services/Edit', [
            'service' => [...$this->serviceData($subscription), 'customer' => $subscription->customer->name],
            'plans' => $plans->map(fn (Plan $plan) => $this->planData($plan)),
        ]);
    }

    public function updateService(UpdateServiceRequest $request, Subscription $subscription, SubscriptionService $service): RedirectResponse
    {
        $this->ownedSubscription($request, $subscription);
        $plan = Plan::query()->publiclyAvailable()->where('product_id', $subscription->product_id)->whereNotNull('price')->where('uuid', $request->validated('plan_uuid'))->firstOrFail();
        $message = 'Service preferences updated.';
        if ($plan->id !== $subscription->plan_id) {
            $result = $service->changePlan($subscription, $plan, $request->user());
            $message = sprintf('Plan changed. Prorated adjustment: %s %s.', $subscription->currency, $result['net_amount']);
        }
        $subscription->update(['auto_renew' => $request->boolean('auto_renew')]);

        return back()->with('success', $message);
    }

    public function suspendService(TransitionServiceRequest $request, Subscription $subscription, SubscriptionService $service): RedirectResponse
    {
        $this->ownedSubscription($request, $subscription);
        $service->suspend($subscription, $request->user(), $request->validated('reason'));

        return redirect()->route('portal.services.index')->with('success', 'Service suspended. Contact support when you are ready to resume it.');
    }

    public function destroyService(TransitionServiceRequest $request, Subscription $subscription, SubscriptionService $service): RedirectResponse
    {
        $this->ownedSubscription($request, $subscription);
        $service->cancel($subscription, $request->user(), $request->validated('reason'));

        return redirect()->route('portal.services.index')->with('success', 'Service cancelled. Its commercial history has been retained.');
    }

    public function licenses(Request $request, LicenseService $service): Response
    {
        $licenses = SoftwareLicense::query()->whereIn('customer_id', $this->customerIds($request))
            ->with(['subscription.product:id,name,code', 'activations' => fn ($query) => $query->latest('activated_at')])->latest()->get();

        return Inertia::render('Customer/Licenses/Index', ['licenses' => $licenses->map(fn (SoftwareLicense $license) => [
            'uuid' => $license->uuid, 'identifier' => 'KBT-LIC-'.strtoupper(substr(str_replace('-', '', $license->uuid), 0, 12)),
            'license_key' => $service->key($license),
            'status' => $license->status, 'product' => $license->subscription?->product?->name,
            'expires_at' => $license->expires_at?->toDateString(), 'max_activations' => $license->max_activations,
            'activations' => $license->activations->map(fn (LicenseActivation $activation) => [
                'uuid' => $activation->uuid, 'device_name' => $activation->device_name ?: 'Unnamed installation',
                'status' => $activation->status, 'activated_at' => $activation->activated_at?->toIso8601String(),
                'last_verified_at' => $activation->last_verified_at?->toIso8601String(),
            ]),
        ])]);
    }

    public function deactivate(Request $request, LicenseActivation $activation): RedirectResponse
    {
        $activation->loadMissing('license');
        abort_unless($request->user()->customers()->whereKey($activation->license->customer_id)->exists(), 403);
        if ($activation->status !== 'active') {
            throw ValidationException::withMessages(['activation' => 'This installation is already inactive.']);
        }
        $activation->update(['status' => 'deactivated', 'deactivated_at' => now()]);

        return back()->with('success', 'Installation deactivated successfully.');
    }

    public function invoices(Request $request): Response
    {
        $invoices = Invoice::query()->whereIn('customer_id', $this->customerIds($request))->with('customer:id,name')->latest()->get();

        return Inertia::render('Customer/Invoices/Index', ['invoices' => $invoices->map(fn (Invoice $invoice) => [
            'uuid' => $invoice->uuid, 'number' => $invoice->invoice_number, 'customer' => $invoice->customer->name,
            'status' => $invoice->status->value, 'issue_date' => $invoice->issue_date->toDateString(), 'due_date' => $invoice->due_date->toDateString(),
            'total' => $invoice->total, 'paid' => $invoice->amount_paid, 'balance' => $invoice->balance, 'currency' => $invoice->currency,
        ])]);
    }

    public function showInvoice(Request $request, Invoice $invoice): Response
    {
        abort_unless($request->user()->customers()->whereKey($invoice->customer_id)->exists(), 403);
        $invoice->load(['customer', 'items', 'payments']);

        return Inertia::render('Invoices/CustomerView', ['invoice' => $invoice, 'canPayOnline' => in_array($invoice->status->value, ['issued', 'partially_paid', 'overdue'], true) && (float) $invoice->balance > 0, 'portal' => true]);
    }

    public function payments(Request $request): Response
    {
        $payments = Payment::query()->whereIn('customer_id', $this->customerIds($request))->with(['customer:id,name', 'allocations.invoice:id,uuid,invoice_number'])->latest('payment_date')->get();

        return Inertia::render('Customer/Payments/Index', ['payments' => $payments->map(fn (Payment $payment) => [
            'uuid' => $payment->uuid, 'number' => $payment->payment_number, 'customer' => $payment->customer->name,
            'amount' => $payment->amount, 'currency' => $payment->currency, 'method' => $payment->payment_method->value,
            'reference' => $payment->transaction_reference, 'date' => $payment->payment_date->toDateString(), 'status' => $payment->status->value,
            'invoices' => $payment->allocations->map(fn ($allocation) => $allocation->invoice?->invoice_number)->filter()->values(),
        ])]);
    }

    public function createPayment(Request $request): Response
    {
        return Inertia::render('Customer/Payments/Create', ['accounts' => $request->user()->customers()->get(['uuid', 'name', 'customer_number'])]);
    }

    public function storePayment(StorePaymentRequest $request, PaymentManager $manager, FileScanner $scanner): RedirectResponse
    {
        $customer = $request->user()->customers()->where('uuid', $request->validated('customer_uuid'))->firstOrFail();
        $scanner->assertClean($request->file('proof_of_payment'));
        $data = $request->safe()->except('customer_uuid', 'proof_of_payment');
        $data['customer_id'] = $customer->id;
        $data['currency'] = strtoupper($data['currency']);
        $data['proof_of_payment'] = $request->file('proof_of_payment')->store('payment-proofs', 'public');
        $manager->create($data, $request->user());

        return redirect()->route('portal.payments.index')->with('success', 'Payment submitted for finance verification.');
    }

    /** @return Collection<int, int> */
    private function customerIds(Request $request): Collection
    {
        return $request->user()->customers()->pluck('customers.id');
    }

    private function ownedSubscription(Request $request, Subscription $subscription): void
    {
        abort_unless($request->user()->customers()->whereKey($subscription->customer_id)->exists(), 403);
    }

    private function primaryCustomer(Request $request): Customer
    {
        return $request->user()->customers()->orderByDesc('customer_user.is_primary')->orderBy('customers.id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function clientSetupData(Subscription $subscription, LicenseService $licenses): array
    {
        $apiUrl = rtrim((string) config('app.url'), '/').'/api/v1';
        $productCode = $subscription->product?->code;
        $license = $subscription->license;
        $licenseKey = $license ? $licenses->key($license) : null;
        $env = implode("\n", [
            'KERNELBRIDGE_API_URL='.$apiUrl,
            'KERNELBRIDGE_PRODUCT_CODE='.($productCode ?: 'replace-with-product-code'),
            'KERNELBRIDGE_API_TOKEN=replace-with-product-machine-token',
            'KERNELBRIDGE_VERIFICATION_INTERVAL=15',
            'KERNELBRIDGE_OFFLINE_GRACE_HOURS=72',
            'KERNELBRIDGE_CACHE_SIGNING_KEY=replace-with-independent-long-random-secret',
            'KERNELBRIDGE_PROTECT_WEB_ROUTES=true',
            'KERNELBRIDGE_PROTECT_EXCEPT=login,register,password/*',
            'KERNELBRIDGE_RENEWAL_NOTICE_ENABLED=true',
        ]);

        return [
            'api_url' => $apiUrl,
            'product_code' => $productCode,
            'license_key' => $licenseKey,
            'has_license' => (bool) $licenseKey,
            'env' => $env,
            'composer_commands' => implode("\n", [
                'composer config repositories.kernelbridge path ../kernelbridge/packages/kernelbridge/licensing-client-laravel',
                'composer require kernelbridge/licensing-client-laravel:^1.0',
                'php artisan vendor:publish --tag=kernelbridge-licensing-config',
                'php artisan vendor:publish --tag=kernelbridge-licensing-migrations',
                'php artisan vendor:publish --tag=kernelbridge-licensing-assets',
                'php artisan migrate',
            ]),
            'route_middleware' => "Route::middleware(['auth', 'kernelbridge.subscription.active'])->group(function (): void {\n    Route::get('/dashboard', DashboardController::class)->name('dashboard');\n});",
        ];
    }

    /** @return array<string, mixed> */
    private function planData(Plan $plan): array
    {
        return ['uuid' => $plan->uuid, 'name' => $plan->name, 'product_uuid' => $plan->product?->uuid, 'product' => $plan->product?->name, 'product_code' => $plan->product?->code,
            'price' => $plan->price, 'currency' => $plan->currency, 'billing_cycle' => $plan->billing_cycle->value,
            'trial_days' => $plan->trial_days, 'description' => $plan->description,
            'features' => $plan->features->map(fn ($feature) => ['key' => $feature->feature_key, 'name' => $feature->feature_name, 'enabled' => $feature->enabled, 'limit' => $feature->limit_value]),
            'limits' => $plan->limits->map(fn ($limit) => ['key' => $limit->limit_key, 'name' => $limit->limit_name, 'value' => $limit->limit_value])];
    }

    /** @return array<string, mixed> */
    private function serviceData(Subscription $item): array
    {
        return ['uuid' => $item->uuid, 'number' => $item->subscription_number, 'status' => $item->status->value,
            'product' => $item->product?->name, 'plan_uuid' => $item->plan?->uuid, 'plan' => $item->plan?->name,
            'price' => $item->price, 'currency' => $item->currency, 'billing_cycle' => $item->billing_cycle->value,
            'auto_renew' => $item->auto_renew];
    }
}


