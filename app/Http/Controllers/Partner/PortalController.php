<?php

namespace App\Http\Controllers\Partner;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Customers\Services\CustomerManager;
use App\Domain\Documents\DocumentAssets;
use App\Domain\Finance\Services\FlutterwavePaymentService;
use App\Domain\Finance\Services\InvoiceManager;
use App\Domain\Finance\Services\PaymentManager;
use App\Domain\Licensing\Services\InstallationActivationService;
use App\Domain\Licensing\Services\PackagePurchaseService;
use App\Domain\Partners\PartnerClientNotifications;
use App\Domain\Partners\PartnerQualificationService;
use App\Domain\Partners\ClientInvitationService;
use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Domain\Support\Services\SupportTicketService;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\LeadStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateRequesterSupportTicketRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\LicenseActivationCode;
use App\Models\PartnerApplication;
use App\Models\PartnerCommission;
use App\Models\PartnerNetworkCommission;
use App\Models\PartnerPointEntry;
use App\Models\BillingSetting;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProspectiveCustomer;
use App\Models\Subscription;
use App\Models\SupportTeam;
use App\Models\SupportTicket;
use App\Notifications\PartnerClientRegistered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PortalController extends Controller
{
    private function clients(Request $request)
    {
        abort_unless($request->user()->isApprovedAgent(), 403, 'An approved sales agent application is required.');

        return Customer::where('partner_user_id', $request->user()->id);
    }

    private function client(Request $request, string $uuid): Customer
    {
        return $this->clients($request)->where('uuid', $uuid)->firstOrFail();
    }

    private function invoice(Request $request, string $uuid): Invoice
    {
        return Invoice::whereIn('customer_id', $this->clients($request)->select('id'))->where('uuid', $uuid)->firstOrFail();
    }

    public function dashboard(Request $request)
    {
        abort_unless($request->user()->isPartner(), 403);
        $approved = $request->user()->isApprovedAgent();

        return Inertia::render('Partner/Dashboard', [
            'applications' => PartnerApplication::where('user_id', $request->user()->id)->whereIn('type', array_keys(config('partners')))->get(),
            'types' => config('partners'), 'canManageClients' => $approved,
            'performance' => $approved ? $this->partnerPerformance($request->user()->id) : null,
        ]);
    }

    private function partnerPerformance(int $partnerId): array
    {
        $qualification = app(PartnerQualificationService::class)->forPartner($partnerId);
        $stages = Lead::query()->where('partner_user_id', $partnerId)
            ->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $start = now()->startOfMonth()->subMonths(5);
        $activity = PartnerPointEntry::query()->where('partner_user_id', $partnerId)
            ->where('status', 'earned')->where('points', '>', 0)
            ->whereIn('event_type', ['pcr_registered', 'visit_recorded', 'consent_recorded', 'opportunity_won'])
            ->where('created_at', '>=', $start)->where('created_at', '<', now()->startOfMonth()->addMonth())
            ->selectRaw('DATE(created_at) as activity_date, SUM(points) as total')
            ->groupBy('activity_date')->get()
            ->groupBy(fn ($row) => substr($row->activity_date, 0, 7));

        return [
            'prospects' => ProspectiveCustomer::where('partner_user_id', $partnerId)->count(),
            'leads' => (int) $stages->sum(),
            'active_clients' => $qualification['active_clients'],
            'qualification' => $qualification,
            'network_policy_enabled' => BillingSetting::findOrFail(1)->partner_network_sharing_enabled,
            'points_balance' => (int) PartnerPointEntry::where('partner_user_id', $partnerId)->where('status', 'earned')->sum('points'),
            'personal_earnings' => PartnerCommission::where('partner_user_id', $partnerId)->where('status', 'earned')
                ->select('currency')->selectRaw('SUM(amount) as total')->groupBy('currency')->get(),
            'network_earnings' => PartnerNetworkCommission::where('partner_user_id', $partnerId)->whereIn('status', ['earned', 'paid'])
                ->select('currency')->selectRaw('SUM(amount) as total')->groupBy('currency')->get(),
            'pipeline' => collect(LeadStatus::cases())->map(fn (LeadStatus $status) => [
                'status' => $status->value,
                'count' => (int) $stages->get($status->value, 0),
            ])->all(),
            'activity' => collect(range(0, 5))->map(function (int $offset) use ($start, $activity): array {
                $month = $start->copy()->addMonths($offset);

                return ['month' => $month->format('M Y'), 'points' => (int) $activity->get($month->format('Y-m'), collect())->sum('total')];
            })->all(),
        ];
    }

    public function customers(Request $request)
    {
        return Inertia::render('Partner/Customers', [
            'customers' => $this->clients($request)
                ->with([
                    'products:id,name',
                    'contacts:id,customer_id,name,job_title,is_primary',
                    'crmLeads' => fn ($query) => $query->where('partner_user_id', $request->user()->id)
                        ->select(['id', 'uuid', 'lead_number', 'name', 'organization', 'status', 'converted_customer_id']),
                ])
                ->latest()->paginate(20),
            'products' => Product::published()->get(['id', 'name']),
        ]);
    }

    public function storeCustomer(Request $request, CustomerManager $customers, AuditRecorder $audit, ClientInvitationService $invitations)
    {
        $this->clients($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CustomerType::class)],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:180', 'required_with:contact_role'],
            'contact_role' => ['nullable', 'string', 'max:120'],
            'organization_type' => ['nullable', Rule::in(['school', 'university', 'healthcare', 'business', 'hospitality', 'government', 'nonprofit', 'other'])],
            'estimated_number_of_users' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'billing_address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'product_ids' => ['nullable', 'array', 'max:100'],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->where('status', 'active')],
        ]);
        if (! empty($data['email']) && Customer::whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])->exists()) {
            throw ValidationException::withMessages(['email' => 'A client with this email already exists. Contact the administration team for assistance.']);
        }

        $customer = DB::transaction(function () use ($customers, $audit, $invitations, $request, $data): Customer {
            $contactName = $data['contact_name'] ?? null;
            $customer = $customers->create([
                'name' => $data['name'],
                'type' => $data['type'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'billing_address' => $data['billing_address'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'metadata' => [
                    'organization_type' => $data['organization_type'] ?? null,
                    'estimated_number_of_users' => isset($data['estimated_number_of_users']) ? (int) $data['estimated_number_of_users'] : null,
                ],
                'product_ids' => $data['product_ids'] ?? [],
                'contacts' => $contactName ? [[
                    'name' => $contactName,
                    'job_title' => $data['contact_role'] ?? null,
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'is_primary' => true,
                ]] : [],
                'status' => CustomerStatus::Active->value,
                'source' => 'partner_direct',
            ], $request->user());
            $customer->forceFill(['partner_user_id' => $request->user()->id])->save();
            $audit->record('partner.client_registered', $request->user(), ['customer_uuid' => $customer->uuid], $customer);
            $invitations->send($customer);
            $request->user()->notify(new PartnerClientRegistered(
                $customer->name, route('partner.customers.subscriptions.create', $customer->uuid), true,
            ));

            return $customer;
        });

        return redirect()->route('partner.customers.subscriptions.create', $customer->uuid)
            ->with('success', 'Client registered successfully. Choose a subscription plan when ready.');
    }

    public function inviteCustomer(Request $request, string $customer, ClientInvitationService $invitations, AuditRecorder $audit)
    {
        $client = $this->client($request, $customer);
        DB::transaction(function () use ($client, $request, $invitations, $audit): void {
            $invitations->send($client);
            $audit->record('partner.client_invitation_sent', $request->user(), ['customer_uuid' => $client->uuid]);
        });

        return back()->with('success', 'Client invitation queued for delivery.');
    }

    public function createCustomerSubscription(Request $request, string $customer)
    {
        $client = $this->client($request, $customer)->load('products:id,name');

        return Inertia::render('Partner/CreateSubscription', [
            'customer' => [
                'uuid' => $client->uuid,
                'name' => $client->name,
                'interested_product_ids' => $client->products->pluck('id'),
            ],
            'products' => Product::published()->get(['id', 'name']),
            'plans' => Plan::publiclyAvailable()->whereNotNull('price')
                ->whereHas('product', fn ($query) => $query->published())
                ->get(['uuid', 'name', 'product_id', 'price', 'currency', 'billing_cycle']),
        ]);
    }

    public function subscriptions(Request $request)
    {
        return Inertia::render('Partner/Subscriptions', [
            'subscriptions' => Subscription::whereIn('customer_id', $this->clients($request)->select('id'))
                ->with(['customer:id,name,email', 'product:id,name,licensing_mode', 'plan:id,name', 'invoice:id,status,balance',
                    'license:id,subscription_id,status', 'license.activationCodes:id,software_license_id,status,created_at'])
                ->latest()->paginate(20, ['id', 'uuid', 'customer_id', 'product_id', 'plan_id', 'invoice_id', 'subscription_number', 'status', 'starts_at', 'ends_at'])
                ->through(fn (Subscription $subscription) => [
                    'uuid' => $subscription->uuid,
                    'customer' => ['name' => $subscription->customer->name],
                    'product' => ['name' => $subscription->product->name],
                    'plan' => ['name' => $subscription->plan->name],
                    'subscription_number' => $subscription->subscription_number,
                    'status' => $subscription->status->value,
                    'starts_at' => $subscription->starts_at?->toIso8601String(),
                    'ends_at' => $subscription->ends_at?->toIso8601String(),
                    'can_generate_activation_code' => in_array($subscription->status->value, ['active', 'grace'], true)
                        && $subscription->invoice?->status->value === 'paid'
                        && (float) $subscription->invoice->balance === 0.0
                        && $subscription->license?->status === 'active'
                        && in_array($subscription->product->licensing_mode, ['direct', 'hybrid'], true)
                        && filled($subscription->customer->email),
                    'has_issued_activation_code' => $subscription->license?->activationCodes->contains(fn (LicenseActivationCode $code) => $code->status === 'issued') ?? false,
                ]),
        ]);
    }

    public function resendActivationCode(
        Request $request,
        Subscription $subscription,
        InstallationActivationService $activation,
        PartnerClientNotifications $notifications,
    ): RedirectResponse {
        $this->clients($request)->whereKey($subscription->customer_id)->firstOrFail();
        $subscription->load(['customer', 'product', 'invoice', 'license.activationCodes']);

        if (! in_array($subscription->status->value, ['active', 'grace'], true)
            || $subscription->invoice?->status->value !== 'paid'
            || (float) $subscription->invoice->balance !== 0.0
            || $subscription->license?->status !== 'active'
            || ! in_array($subscription->product->licensing_mode, ['direct', 'hybrid'], true)) {
            throw ValidationException::withMessages(['activation_code' => 'A paid, active subscription with a direct or hybrid license is required.']);
        }

        if (! filled($subscription->customer->email)) {
            throw ValidationException::withMessages(['activation_code' => 'Add a client email address before sending an activation code.']);
        }

        $unusedCode = $subscription->license->activationCodes
            ->where('status', 'issued')
            ->sortByDesc('created_at')
            ->first();
        $issued = $activation->issueCode($subscription->license, $request->user(), $unusedCode?->uuid);
        $notifications->activationCode(
            $subscription,
            $issued['code'],
            $issued['activation_code']->expires_at,
            $unusedCode !== null,
        );

        return back()
            ->with('success', 'A fresh activation code was generated and emailed to the client. Any unused previous code was invalidated.')
            ->with('activation_code', [
                'code' => $issued['code'],
                'expires_at' => $issued['activation_code']->expires_at->toIso8601String(),
                'customer' => $subscription->customer->name,
                'product' => $subscription->product->name,
            ]);
    }

    public function activationCodes(Request $request)
    {
        $clientIds = $this->clients($request)->select('id');
        $codes = LicenseActivationCode::whereHas('license', fn ($query) => $query->whereIn('customer_id', $clientIds))
            ->with(['license:id,customer_id', 'license.customer:id,name', 'product:id,name'])
            ->latest()->paginate(20, ['id', 'uuid', 'software_license_id', 'product_id', 'status', 'expires_at', 'created_at']);
        $codes->through(fn (LicenseActivationCode $code) => [
            'uuid' => $code->uuid,
            'customer' => $code->license->customer->name,
            'product' => $code->product->name,
            'status' => $code->status === 'issued' && $code->expires_at?->isPast() ? 'expired' : $code->status,
            'expires_at' => $code->expires_at?->toIso8601String(),
            'created_at' => $code->created_at->toIso8601String(),
        ]);

        return Inertia::render('Partner/ActivationCodes', ['codes' => $codes]);
    }

    public function updateCustomer(Request $request, string $customer)
    {
        $client = $this->client($request, $customer);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:30', 'required_without:email'],
        ]);
        $client->update($data);

        return back()->with('success', 'Client updated.');
    }

    public function invoices(Request $request)
    {
        $clients = $this->clients($request);

        return Inertia::render('Partner/Invoices', [
            'invoices' => Invoice::whereIn('customer_id', (clone $clients)->select('id'))->with('customer:id,name')->latest()->paginate(20),
            'customers' => $clients->get(['uuid', 'name']),
            'plans' => Plan::publiclyAvailable()->whereNotNull('price')->with('product:id,name,code')->get(['id', 'uuid', 'name', 'product_id', 'price', 'currency', 'billing_cycle']),
        ]);
    }

    public function storeInvoice(Request $request, InvoiceManager $invoices, SubscriptionService $subscriptions)
    {
        $data = $request->validate(['customer_uuid' => ['required', 'uuid'], 'plan_uuid' => ['required', 'uuid']]);
        $customer = $this->client($request, $data['customer_uuid']);
        $plan = Plan::publiclyAvailable()->whereNotNull('price')->where('uuid', $data['plan_uuid'])->with('product')->firstOrFail();
        if ($plan->product->code === 'MEDIFLOW') {
            $invoice = app(PackagePurchaseService::class)->purchase($customer, [$plan->uuid], $request->user());
        } else {
            $invoice = DB::transaction(function () use ($customer, $plan, $request, $invoices, $subscriptions) {
                Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                if (Subscription::where('customer_id', $customer->id)->where('product_id', $plan->product_id)->whereIn('status', ['pending', 'trial', 'active', 'grace', 'suspended'])->exists()) {
                    throw ValidationException::withMessages(['plan_uuid' => 'This client already has a current subscription for this product.']);
                }
                $invoice = $invoices->create(['customer_id' => $customer->id, 'issue_date' => today(), 'due_date' => today()->addDays(7), 'currency' => $plan->currency,
                    'items' => [['product_id' => $plan->product_id, 'plan_id' => $plan->id, 'description' => $plan->product->name.' - '.$plan->name, 'quantity' => 1, 'unit_price' => $plan->price, 'discount' => 0, 'tax' => 0]]], $request->user());
                $subscriptions->create($customer, $plan, $invoice, $request->user(), ['auto_renew' => false, 'metadata' => ['checkout' => true]]);

                return $invoice;
            });
            $invoices->issue($invoice, $request->user());
        }

        return redirect()->route('partner.invoices.show', $invoice->uuid)->with('success', 'Invoice created at the published plan price.');
    }

    public function showInvoice(Request $request, string $invoice, DocumentAssets $assets)
    {
        $invoice = $this->invoice($request, $invoice)->load(['customer:id,name,billing_address', 'items', 'payments']);

        return Inertia::render('Partner/Invoice', [
            'invoice' => $invoice,
            'documentAssets' => $assets->images(),
            'canPayOnline' => filled(config('services.flutterwave.secret_key'))
                && in_array($invoice->status->value, ['issued', 'partially_paid', 'overdue'], true)
                && (float) $invoice->balance > 0,
        ]);
    }

    public function pay(Request $request, string $invoice, FlutterwavePaymentService $service)
    {
        $url = $service->initialize($this->invoice($request, $invoice), $request->user());

        return $request->header('X-Inertia') ? Inertia::location($url) : redirect()->away($url);
    }

    public function payments(Request $request)
    {
        return Inertia::render('Partner/Payments', ['payments' => Payment::whereIn('customer_id', $this->clients($request)->select('id'))->with('customer:id,name')->latest()->paginate(20)]);
    }

    public function recordPayment(Request $request, string $invoice, PaymentManager $service)
    {
        $invoice = $this->invoice($request, $invoice);
        abort_unless(in_array($invoice->status->value, ['issued', 'partially_paid', 'overdue']), 422, 'This invoice is not payable.');
        $data = $request->validate(['amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:'.$invoice->balance],
            'payment_method' => ['required', Rule::in(['bank_transfer', 'cash', 'pos'])],
            'transaction_reference' => ['required', 'string', 'max:255', 'unique:payments,transaction_reference'],
            'payment_date' => ['required', 'date', 'before_or_equal:today']]);
        $service->create($data + ['customer_id' => $invoice->customer_id, 'currency' => $invoice->currency, 'metadata' => ['invoice_uuid' => $invoice->uuid], 'notes' => 'Partner payment submission for '.$invoice->invoice_number], $request->user());

        return back()->with('success', 'Payment submitted for finance verification.');
    }

    public function revenue(Request $request)
    {
        $this->clients($request);

        return redirect()->route('partner.commission');
    }

    public function commission(Request $request)
    {
        $ids = $this->clients($request)->select('id');
        $partnerId = $request->user()->id;
        $personalEarnings = PartnerCommission::where('partner_user_id', $partnerId)->where('status', 'earned')
            ->select('currency')->selectRaw('SUM(amount) as total')->groupBy('currency')->get();
        $networkEarnings = PartnerNetworkCommission::where('partner_user_id', $partnerId)->whereIn('status', ['earned', 'paid'])
            ->select('currency')->selectRaw('SUM(amount) as total')->groupBy('currency')->get();
        $totalEarnings = $personalEarnings->concat($networkEarnings)->groupBy('currency')->map(fn ($rows, $currency) => [
            'currency' => $currency,
            'total' => number_format($rows->sum(fn ($row) => (float) $row->total), 2, '.', ''),
        ])->values();
        $partnerCategory = PartnerApplication::query()
            ->where('user_id', $partnerId)
            ->where('type', 'sales_agent')
            ->where('status', 'approved')
            ->value('partner_category');

        return Inertia::render('Partner/Commission', [
            'commissions' => PartnerCommission::where('partner_user_id', $partnerId)->with(['invoice:id,uuid,invoice_number', 'product:id,name'])->latest()->paginate(20, ['*'], 'personal_page'),
            ...$this->networkCommissionData($partnerId),
            'personalEarnings' => $personalEarnings,
            'earnings' => $personalEarnings,
            'networkEarnings' => $networkEarnings,
            'totalEarnings' => $totalEarnings,
            'rates' => Product::published()->get(['name', 'partner_commission_rate', 'organization_partner_commission_rate'])->map(fn (Product $product) => [
                'name' => $product->name,
                'partner_commission_rate' => $partnerCategory === 'organization'
                    ? $product->organization_partner_commission_rate
                    : $product->partner_commission_rate,
            ]),
            'collections' => Payment::whereIn('customer_id', clone $ids)->where('status', 'verified')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payments as refunds')->whereColumn('refunds.parent_payment_id', 'payments.id')->where('refunds.status', 'refunded'))->select('currency')->selectRaw('SUM(amount) as total')->groupBy('currency')->get(),
            'outstanding' => Invoice::whereIn('customer_id', clone $ids)->whereIn('status', ['issued', 'partially_paid', 'overdue'])->select('currency')->selectRaw('SUM(balance) as total')->groupBy('currency')->get(),
        ]);
    }

    public function networkCommission(Request $request)
    {
        $this->clients($request);
        $partnerId = $request->user()->id;

        return Inertia::render('Partner/NetworkCommission', [
            ...$this->networkCommissionData($partnerId),
            'networkEarnings' => PartnerNetworkCommission::where('partner_user_id', $partnerId)->whereIn('status', ['earned', 'paid'])
                ->select('currency')->selectRaw('SUM(amount) as total')->groupBy('currency')->get(),
        ]);
    }

    private function networkCommissionData(int $partnerId): array
    {
        return [
            'networkCommissions' => PartnerNetworkCommission::where('partner_user_id', $partnerId)
                ->with('product:id,name')->latest('earned_at')
                ->paginate(20, ['id', 'partner_user_id', 'product_id', 'partner_level', 'level_weight', 'pool_rate', 'payment_base_amount', 'platform_amount', 'pool_amount', 'amount', 'currency', 'status', 'earned_at', 'paid_at'], 'network_page'),
            'qualification' => app(PartnerQualificationService::class)->forPartner($partnerId),
            'networkPolicyEnabled' => BillingSetting::findOrFail(1)->partner_network_sharing_enabled,
        ];
    }

    public function effortPoints(Request $request)
    {
        $this->clients($request);
        $partnerId = $request->user()->id;
        $events = [
            'pcr_registered' => 'Prospects registered',
            'visit_recorded' => 'Visits / contacts recorded',
            'consent_recorded' => 'Consent and CRM registrations',
            'opportunity_won' => 'CRM opportunities won',
        ];
        $ownTotals = PartnerPointEntry::query()->where('partner_user_id', $partnerId)
            ->where('status', 'earned')->where('points', '>', 0)->whereIn('event_type', array_keys($events))
            ->select('event_type')->selectRaw('SUM(points) as total')->groupBy('event_type')->pluck('total', 'event_type');
        $otherPartnerIds = PartnerApplication::query()->where('type', 'sales_agent')->where('status', 'approved')
            ->where('user_id', '!=', $partnerId)
            ->whereHas('user', fn ($query) => $query->role(UserRole::Agent->value))
            ->pluck('user_id');
        $othersByEvent = collect();
        $comparisonAvailable = $otherPartnerIds->count() >= 3;

        if ($comparisonAvailable) {
            $othersByEvent = PartnerPointEntry::query()->whereIn('partner_user_id', $otherPartnerIds)
                ->where('status', 'earned')->where('points', '>', 0)->whereIn('event_type', array_keys($events))
                ->select('event_type')->selectRaw('SUM(points) as total')->groupBy('event_type')->pluck('total', 'event_type');
        }

        $comparison = collect($events)->map(fn (string $label, string $event) => [
            'event_type' => $event,
            'label' => $label,
            'your_points' => (int) $ownTotals->get($event, 0),
            'other_average' => $comparisonAvailable ? round((float) $othersByEvent->get($event, 0) / $otherPartnerIds->count(), 1) : 0,
        ])->values();

        return Inertia::render('Partner/EffortPoints', [
            'points' => [
                'balance' => (int) PartnerPointEntry::query()->where('partner_user_id', $partnerId)->where('status', 'earned')->sum('points'),
                'value' => BillingSetting::findOrFail(1)->partner_point_value,
            ],
            'pointBonuses' => PartnerPointEntry::query()->where('partner_user_id', $partnerId)
                ->where('event_type', 'payment_redemption')->where('status', 'earned')
                ->select('currency')->selectRaw('SUM(amount) as total')->groupBy('currency')->get(),
            'pointEntries' => PartnerPointEntry::query()->where('partner_user_id', $partnerId)
                ->with(['prospect:id,name,organization', 'lead:id,lead_number', 'payment:id,payment_number', 'invoicePayment:id,reference'])
                ->latest()->paginate(10),
            'comparison' => $comparison,
            'comparisonAvailable' => $comparisonAvailable,
        ]);
    }

    public function support(Request $request)
    {
        $clients = $this->clients($request);

        return Inertia::render('Partner/Support', [
            'tickets' => SupportTicket::whereIn('customer_id', (clone $clients)->select('id'))->with('customer:id,name')->latest()->paginate(20),
            'customers' => $clients->get(['uuid', 'name']), 'teams' => SupportTeam::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['uuid', 'name']),
        ]);
    }

    public function storeSupport(Request $request, SupportTicketService $service)
    {
        $data = $request->validate([
            'customer_uuid' => ['required', 'uuid'],
            'team_uuid' => ['required', 'uuid', Rule::exists('support_teams', 'uuid')->where('is_active', true)],
            'subject' => ['required', 'string', 'min:5', 'max:255'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'message' => ['required', 'string', 'min:10', 'max:20000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['required', 'file', 'mimes:pdf,txt,jpg,jpeg,png,webp,zip', 'max:10240'],
        ]);
        $ticket = $service->create($this->client($request, $data['customer_uuid']), $data, $request->user());

        return redirect()->route('partner.support.show', $ticket->uuid);
    }

    private function ticket(Request $request, string $uuid): SupportTicket
    {
        return SupportTicket::whereIn('customer_id', $this->clients($request)->select('id'))->where('uuid', $uuid)->firstOrFail();
    }

    public function showSupport(Request $request, string $ticket)
    {
        $ticket = $this->ticket($request, $ticket)->load([
            'customer:id,uuid,name',
            'product:id,uuid,name',
            'team:id,uuid,name',
            'messages' => fn ($query) => $query->where('is_customer_visible', true)->with(['sender:id,name', 'attachments']),
        ]);
        $customer = $ticket->customer;
        $productIds = $customer->products()->pluck('products.id')->merge(
            $customer->subscriptions()->whereIn('status', ['pending', 'trial', 'active', 'grace', 'suspended'])->pluck('product_id'),
        )->unique();

        return Inertia::render('Partner/Ticket', [
            'ticket' => $ticket,
            'products' => Product::query()->published()->whereIn('id', $productIds)->get(['uuid', 'name']),
            'teams' => SupportTeam::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['uuid', 'name']),
        ]);
    }

    public function reply(Request $request, string $ticket, SupportTicketService $service)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:20000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['required', 'file', 'mimes:pdf,txt,jpg,jpeg,png,webp,zip', 'max:10240'],
        ]);
        $service->message($this->ticket($request, $ticket), $data['message'], $request->file('attachments', []), $request->user());

        return back()->with('success', 'Reply sent.');
    }

    public function updateSupport(UpdateRequesterSupportTicketRequest $request, SupportTicket $ticket, SupportTicketService $service)
    {
        $this->ticket($request, $ticket->uuid);
        $service->updateByRequester($ticket, $request->validated(), $request->user());

        return back()->with('success', 'Support ticket updated.');
    }

    public function resolveSupport(Request $request, string $ticket, SupportTicketService $service)
    {
        $ticket = $this->ticket($request, $ticket);
        $this->authorize('resolveByRequester', $ticket);
        $service->resolveByRequester($ticket, $request->user());

        return back()->with('success', 'Support ticket marked as resolved.');
    }
}
