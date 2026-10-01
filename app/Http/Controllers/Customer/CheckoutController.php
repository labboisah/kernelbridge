<?php

namespace App\Http\Controllers\Customer;

use App\Domain\Finance\Services\InvoiceManager;
use App\Domain\Licensing\Services\ClientSetupService;
use App\Domain\Licensing\Services\ImplementationPricing;
use App\Domain\Licensing\Services\PackageCatalog;
use App\Domain\Licensing\Services\PackagePurchaseService;
use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\HostingSetting;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class CheckoutController extends Controller
{
    private function plans(Request $request)
    {
        $data = $request->validate(['packages' => ['required', 'array', 'min:1', 'max:7'], 'packages.*' => ['required', 'uuid', 'distinct']]);
        $plans = Plan::publiclyAvailable()->with(['product', 'features'])->whereIn('uuid', $data['packages'])->whereHas('product', fn ($q) => $q->published())->get();
        abort_unless($plans->count() === count($data['packages']), 404);
        if ($plans->pluck('product_id')->unique()->count() !== 1 || $plans->pluck('currency')->unique()->count() !== 1 || $plans->pluck('billing_cycle')->unique()->count() !== 1 || ($plans->count() > 1 && $plans->first()->product->code !== 'MEDIFLOW')) {
            throw ValidationException::withMessages(['packages' => 'Select compatible packages from one product.']);
        }

        return $plans;
    }

    public function show(Request $request)
    {
        $plans = $this->plans($request);

        return Inertia::render('Customer/Checkout', [
            'customer' => $request->user()->customers()->orderByDesc('customer_user.is_primary')->firstOrFail()->only(['name', 'email']),
            'plans' => $plans->map(fn ($p) => ['uuid' => $p->uuid, 'name' => $p->name, 'description' => $p->description, 'product' => $p->product->name, 'price' => $p->price, 'setup_fee' => $p->setup_fee, 'currency' => $p->currency, 'billing_cycle' => $p->billing_cycle->value, 'features' => $p->product->code === 'MEDIFLOW' ? app(PackageCatalog::class)->plan($p)['features'] : $p->features->where('enabled', true)->map(fn ($f) => ['key' => $f->feature_key, 'name' => $f->feature_name, 'description' => $f->description ?? ''])->values()]),
            'vatRate' => \App\Models\BillingSetting::vatRate(),
            'hosting' => HostingSetting::findOrFail(1)->only(['enabled', 'currency', 'monthly_price']),
        ]);
    }

    public function store(Request $request)
    {
        $plans = $this->plans($request);
        $data = $request->validate(['implementation.mode' => ['required', 'in:local,online'], 'implementation.domain' => ['nullable', 'string', 'max:253', 'regex:/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/']]);
        $customer = $request->user()->customers()->orderByDesc('customer_user.is_primary')->firstOrFail();
        $plan = $plans->first();
        if ($plans->contains(fn ($p) => $p->price === null || (float) $p->price <= 0)) {
            throw ValidationException::withMessages(['packages' => 'Contact sales to price this plan before checkout.']);
        }
        $invoice = DB::transaction(function () use ($plans, $customer, $plan, $data, $request) {
            if ($plan->product->code === 'MEDIFLOW') {
                return app(PackagePurchaseService::class)->purchase($customer, $plans->pluck('uuid')->all(), $request->user(), $data['implementation']);
            }
            Customer::lockForUpdate()->findOrFail($customer->id);
            if (Subscription::where('customer_id', $customer->id)->where('product_id', $plan->product_id)->whereIn('status', ['pending', 'active', 'trial', 'grace', 'suspended'])->exists()) {
                throw ValidationException::withMessages(['packages' => 'Manage the existing service for this product from your account.']);
            }
            $implementation = app(ImplementationPricing::class)->selection($plan, $data['implementation']);
            $items = [['product_id' => $plan->product_id, 'plan_id' => $plan->id, 'description' => $plan->name, 'quantity' => 1, 'unit_price' => $plan->price, 'discount' => 0, 'tax' => 0]];
            if ((float) $plan->setup_fee > 0) {
                $items[] = ['product_id' => $plan->product_id, 'plan_id' => $plan->id, 'description' => 'One-time setup', 'quantity' => 1, 'unit_price' => $plan->setup_fee, 'discount' => 0, 'tax' => 0];
            }
            $items = array_merge($items, app(ImplementationPricing::class)->lines($implementation, $plan->product_id));
            $invoices = app(InvoiceManager::class);
            $invoice = $invoices->create(['customer_id' => $customer->id, 'currency' => $plan->currency, 'issue_date' => today(), 'due_date' => today()->addDays(7), 'items' => $items, 'terms' => 'Activation is issued only after full verified payment. Hosting is prepaid for the selected term. Domain requests are subject to availability; infrastructure provisioning is coordinated by support.'], $request->user());
            app(SubscriptionService::class)->create($customer, $plan, $invoice, $request->user(), ['auto_renew' => false, 'metadata' => ['checkout' => true, 'implementation' => $implementation]]);
            $invoices->issue($invoice, $request->user());

            return $invoice;
        });

        return redirect()->route('portal.invoices.show', $invoice)->with('success', 'Order confirmed. Proceed to payment with Flutterwave to receive your activation code and client settings.');
    }

    public function environment(Request $request, Subscription $subscription, ClientSetupService $setup)
    {
        abort_unless($request->user()->customers()->whereKey($subscription->customer_id)->exists(), 403);

        return response($setup->environment($subscription), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="client-licensing.env"', 'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }
}
