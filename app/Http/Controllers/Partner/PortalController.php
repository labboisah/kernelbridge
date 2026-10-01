<?php

namespace App\Http\Controllers\Partner;

use App\Domain\Finance\Services\FlutterwavePaymentService;
use App\Domain\Finance\Services\InvoiceManager;
use App\Domain\Finance\Services\PaymentManager;
use App\Domain\Licensing\Services\PackagePurchaseService;
use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Domain\Support\Services\SupportTicketService;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PartnerApplication;
use App\Models\PartnerCommission;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SupportTeam;
use App\Models\SupportTicket;
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

        return Inertia::render('Partner/Dashboard', [
            'applications' => PartnerApplication::where('user_id', $request->user()->id)->whereIn('type', array_keys(config('partners')))->get(),
            'types' => config('partners'), 'canManageClients' => $request->user()->isApprovedAgent(),
        ]);
    }

    public function customers(Request $request)
    {
        return Inertia::render('Partner/Customers', ['customers' => $this->clients($request)->latest()->paginate(20)]);
    }

    public function storeCustomer(Request $request)
    {
        $this->clients($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'type' => ['required', Rule::in(['individual', 'organization'])]]);
        DB::transaction(function () use ($request, $data) {
            $customer = new Customer($data + ['status' => 'prospect', 'source' => 'partner_portal']);
            $customer->forceFill(['partner_user_id' => $request->user()->id])->save();
            $customer->update(['customer_number' => sprintf('KBT-CUS-%06d', $customer->id)]);
        });

        return back()->with('success', 'Client created.');
    }

    public function updateCustomer(Request $request, string $customer)
    {
        $client = $this->client($request, $customer);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30']]);
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

    public function showInvoice(Request $request, string $invoice)
    {
        return Inertia::render('Partner/Invoice', ['invoice' => $this->invoice($request, $invoice)->load(['customer:id,name,email', 'items'])]);
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
        $ids = $this->clients($request)->select('id');

        return Inertia::render('Partner/Revenue', [
            'commissions' => PartnerCommission::where('partner_user_id', $request->user()->id)->with(['invoice:id,uuid,invoice_number', 'product:id,name'])->latest()->paginate(20),
            'earnings' => PartnerCommission::where('partner_user_id', $request->user()->id)->where('status', 'earned')->select('currency')->selectRaw('SUM(amount) as total')->groupBy('currency')->get(),
            'rates' => Product::published()->get(['name', 'partner_commission_rate']),
            'collections' => Payment::whereIn('customer_id', clone $ids)->where('status', 'verified')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payments as refunds')->whereColumn('refunds.parent_payment_id', 'payments.id')->where('refunds.status', 'refunded'))->select('currency')->selectRaw('SUM(amount) as total')->groupBy('currency')->get(),
            'outstanding' => Invoice::whereIn('customer_id', clone $ids)->whereIn('status', ['issued', 'partially_paid', 'overdue'])->select('currency')->selectRaw('SUM(balance) as total')->groupBy('currency')->get(),
        ]);
    }

    public function support(Request $request)
    {
        $clients = $this->clients($request);

        return Inertia::render('Partner/Support', [
            'tickets' => SupportTicket::whereIn('customer_id', (clone $clients)->select('id'))->with('customer:id,name')->latest()->paginate(20),
            'customers' => $clients->get(['uuid', 'name']), 'teams' => SupportTeam::where('is_active', true)->get(['uuid', 'name']),
        ]);
    }

    public function storeSupport(Request $request, SupportTicketService $service)
    {
        $data = $request->validate(['customer_uuid' => ['required', 'uuid'], 'team_uuid' => ['required', 'uuid', Rule::exists('support_teams', 'uuid')->where('is_active', true)], 'subject' => ['required', 'string', 'min:5', 'max:255'], 'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])], 'message' => ['required', 'string', 'min:10', 'max:20000']]);
        $ticket = $service->create($this->client($request, $data['customer_uuid']), $data, $request->user());

        return redirect()->route('partner.support.show', $ticket->uuid);
    }

    private function ticket(Request $request, string $uuid): SupportTicket
    {
        return SupportTicket::whereIn('customer_id', $this->clients($request)->select('id'))->where('uuid', $uuid)->firstOrFail();
    }

    public function showSupport(Request $request, string $ticket)
    {
        return Inertia::render('Partner/Ticket', ['ticket' => $this->ticket($request, $ticket)->load(['customer:id,name', 'messages' => fn ($q) => $q->where('is_customer_visible', true)->with('sender:id,name')])]);
    }

    public function reply(Request $request, string $ticket, SupportTicketService $service)
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:2', 'max:20000']]);
        $service->message($this->ticket($request,$ticket),$data['message'],[],$request->user());

        return back()->with('success','Reply sent.');
    }
}
