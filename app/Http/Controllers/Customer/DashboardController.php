<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        if ($request->user()->isPartner()) {
            return redirect()->route('partner.dashboard');
        }
        $customers = $request->user()->customers()->get();
        $customerIds = $customers->pluck('id');
        $subscriptions = Subscription::query()->whereIn('customer_id', $customerIds)->with(['product:id,uuid,name,code', 'plan:id,uuid,name'])->latest()->get();
        $invoices = Invoice::query()->whereIn('customer_id', $customerIds)->latest()->limit(10)->get();
        $quotations = Quotation::query()->whereIn('customer_id', $customerIds)->latest()->limit(10)->get();
        $payments = Payment::query()->whereIn('customer_id', $customerIds)->latest('payment_date')->limit(10)->get();
        $licenses = SoftwareLicense::query()->whereIn('customer_id', $customerIds)->with(['subscription.product:id,uuid,name,code', 'activations'])->latest()->get();
        $tickets = SupportTicket::query()->whereIn('customer_id', $customerIds)->with('product:id,uuid,name')->latest('opened_at')->limit(5)->get();

        return Inertia::render('Customer/Dashboard', [
            'accounts' => $customers->map->only(['uuid', 'customer_number', 'name', 'status']),
            'summary' => [
                'active_subscriptions' => Subscription::query()->whereIn('customer_id', $customerIds)->whereIn('status', ['active', 'trial', 'grace'])->count(),
                'active_licenses' => SoftwareLicense::query()->whereIn('customer_id', $customerIds)->where('status', 'active')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
                'outstanding_balance' => number_format((float) Invoice::query()->whereIn('customer_id', $customerIds)->sum('balance'), 2, '.', ''),
                'open_tickets' => SupportTicket::query()->whereIn('customer_id', $customerIds)->whereIn('status', ['open', 'in_progress', 'waiting_customer'])->count(),
            ],
            'subscriptions' => $subscriptions->map(fn (Subscription $subscription): array => [
                'uuid' => $subscription->uuid, 'number' => $subscription->subscription_number, 'status' => $subscription->status->value,
                'product' => $subscription->product?->name, 'plan' => $subscription->plan?->name,
                'ends_at' => $subscription->ends_at?->toDateString(), 'auto_renew' => $subscription->auto_renew,
            ]),
            'licenses' => $licenses->map(fn (SoftwareLicense $license): array => [
                'uuid' => $license->uuid, 'status' => $license->status, 'product' => $license->subscription?->product?->name,
                'expires_at' => $license->expires_at?->toDateString(), 'activations' => $license->activations->where('status', 'active')->count(),
                'max_activations' => $license->max_activations,
            ]),
            'invoices' => $invoices->map(fn (Invoice $invoice): array => [
                'uuid' => $invoice->uuid, 'number' => $invoice->invoice_number, 'status' => $invoice->status->value,
                'total' => $invoice->total, 'balance' => $invoice->balance, 'currency' => $invoice->currency,
                'due_date' => $invoice->due_date->toDateString(),
                'url' => URL::temporarySignedRoute('invoices.customer.show', now()->addMinutes(30), ['invoice' => $invoice]),
            ]),
            'quotations' => $quotations->map(fn (Quotation $quotation): array => [
                'uuid' => $quotation->uuid, 'number' => $quotation->quotation_number, 'status' => $quotation->status->value,
                'total' => $quotation->total, 'currency' => $quotation->currency, 'expiry_date' => $quotation->expiry_date->toDateString(),
                'url' => URL::temporarySignedRoute('quotations.customer.show', now()->addMinutes(30), ['quotation' => $quotation]),
            ]),
            'payments' => $payments->map(fn (Payment $payment): array => [
                'uuid' => $payment->uuid, 'number' => $payment->payment_number, 'status' => $payment->status->value,
                'amount' => $payment->amount, 'currency' => $payment->currency, 'payment_date' => $payment->payment_date->toDateString(),
            ]),
            'tickets' => $tickets->map(fn (SupportTicket $ticket): array => [
                'uuid' => $ticket->uuid, 'number' => $ticket->ticket_number, 'subject' => $ticket->subject,
                'status' => $ticket->status->value, 'priority' => $ticket->priority->value, 'product' => $ticket->product?->name,
            ]),
        ]);
    }
}
