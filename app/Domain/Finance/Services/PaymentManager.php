<?php

namespace App\Domain\Finance\Services;

use App\Domain\Licensing\Services\PaidLicenseService;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\CustomerCreditEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPackageOrder;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class PaymentManager
{
    public function __construct(private FinancialAuditLogger $audit) {}

    public function create(array $data, User $actor): Payment
    {
        return DB::transaction(function () use ($data, $actor): Payment {
            $payment = Payment::query()->create([...Arr::except($data, ['allocations']), 'provider' => $data['provider'] ?? 'manual', 'recorded_by' => $actor->id, 'status' => PaymentStatus::Pending]);
            $payment->forceFill(['payment_number' => sprintf('KBT-PAY-%s-%06d', $payment->payment_date->format('Y'), $payment->id)])->save();
            $this->audit->record('payment.recorded', $payment, $actor, ['amount' => $payment->amount, 'currency' => $payment->currency, 'reference' => $payment->transaction_reference]);

            return $payment;
        });
    }

    public function verify(Payment $payment, array $allocations, User $actor): Payment
    {
        return DB::transaction(function () use ($payment, $allocations, $actor): Payment {
            Customer::query()->lockForUpdate()->findOrFail($payment->customer_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages(['status' => 'Only pending payments can be verified.']);
            }
            $allocated = 0;
            foreach ($allocations as $index => $allocation) {
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($allocation['invoice_id']);
                $amount = $this->minor((string) $allocation['amount']);
                if ($invoice->customer_id !== $payment->customer_id || $invoice->currency !== $payment->currency) {
                    throw ValidationException::withMessages(["allocations.$index.invoice_id" => 'Invoice customer and currency must match the payment.']);
                }
                if (! in_array($invoice->status->value, ['issued', 'partially_paid', 'overdue'], true) || $amount > $this->minor($invoice->balance)) {
                    throw ValidationException::withMessages(["allocations.$index.amount" => 'Allocation exceeds the eligible invoice balance.']);
                }
                $allocated += $amount;
                if ($allocated > $this->minor($payment->amount)) {
                    throw ValidationException::withMessages(['allocations' => 'Allocations cannot exceed the payment amount.']);
                }
                $payment->allocations()->create(['invoice_id' => $invoice->id, 'amount' => $this->format($amount), 'allocated_by' => $actor->id, 'allocated_at' => now()]);
                $this->applyToInvoice($invoice, $amount);
            }
            if ($allocated !== $this->minor($payment->amount)) {
                throw ValidationException::withMessages(['allocations' => 'The full payment amount must be allocated before verification.']);
            }
            $payment->update(['status' => PaymentStatus::Verified, 'verified_by' => $actor->id, 'verified_at' => now()]);
            foreach ($payment->allocations()->with('invoice')->get() as $allocation) {
                app(PaidLicenseService::class)->complete($allocation->invoice, $actor);
                app(\App\Domain\Partners\CommissionService::class)->accrue($allocation->invoice);
            }
            $this->audit->record('payment.verified', $payment, $actor, ['allocations' => $allocations]);

            return $payment->refresh();
        });
    }

    public function reject(Payment $payment, User $actor, ?string $notes): Payment
    {
        return DB::transaction(function () use ($payment, $actor, $notes): Payment {
            Customer::query()->lockForUpdate()->findOrFail($payment->customer_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages(['status' => 'Only pending payments can be rejected.']);
            }
            $payment->update(['status' => PaymentStatus::Rejected, 'verified_by' => $actor->id, 'verified_at' => now(), 'notes' => $notes ?: $payment->notes]);
            $this->audit->record('payment.rejected', $payment, $actor, ['notes' => $notes]);

            return $payment;
        });
    }

    public function refund(Payment $payment, User $actor, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $actor, $reason): Payment {
            Customer::query()->lockForUpdate()->findOrFail($payment->customer_id);
            $payment = Payment::query()->lockForUpdate()->with('allocations')->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::Verified || Payment::query()->where('parent_payment_id', $payment->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'Only an unreversed verified payment can be refunded.']);
            }
            foreach ($payment->allocations as $allocation) {
                $order = SubscriptionPackageOrder::query()->where('invoice_id', $allocation->invoice_id)->first();
                $subscriptionIds = Subscription::query()->where('invoice_id', $allocation->invoice_id)->pluck('id');
                if ($order || CustomerCreditEntry::query()->whereIn('subscription_id', $subscriptionIds)->exists()
                    || CustomerCreditEntry::query()->where('invoice_id', $allocation->invoice_id)->exists()) {
                    throw ValidationException::withMessages(['payment' => 'This invoice has package adjustments or credit. Reconcile its credit ledger before a cash refund.']);
                }
                $invoice = Invoice::query()->lockForUpdate()->findOrFail($allocation->invoice_id);
                $this->reverseInvoice($invoice, $this->minor($allocation->amount));
                app(\App\Domain\Partners\CommissionService::class)->reverse($invoice);
                foreach (Subscription::query()->where('invoice_id', $invoice->id)->get() as $subscription) {
                    if (data_get($subscription->metadata, 'selected_packages')) {
                        $subscription->update(['status' => 'suspended']);
                    }
                }
            }
            $refund = Payment::query()->create(['customer_id' => $payment->customer_id, 'amount' => $payment->amount, 'currency' => $payment->currency, 'payment_method' => $payment->payment_method, 'transaction_reference' => 'REFUND-'.$payment->transaction_reference, 'payment_date' => today(), 'status' => PaymentStatus::Refunded, 'notes' => $reason, 'provider' => $payment->provider, 'recorded_by' => $actor->id, 'verified_by' => $actor->id, 'verified_at' => now(), 'parent_payment_id' => $payment->id]);
            $refund->forceFill(['payment_number' => sprintf('KBT-RFD-%s-%06d', now()->year, $refund->id)])->save();
            $this->audit->record('payment.refunded', $refund, $actor, ['original_payment_uuid' => $payment->uuid, 'reason' => $reason]);

            return $refund;
        });
    }

    private function applyToInvoice(Invoice $invoice, int $amount): void
    {
        $paid = $this->minor($invoice->amount_paid) + $amount;
        $balance = $this->minor($invoice->total) - $paid;
        $invoice->update(['amount_paid' => $this->format($paid), 'balance' => $this->format($balance), 'status' => $balance === 0 ? 'paid' : 'partially_paid', 'paid_at' => $balance === 0 ? now() : null]);
    }

    private function reverseInvoice(Invoice $invoice, int $amount): void
    {
        $paid = max(0, $this->minor($invoice->amount_paid) - $amount);
        $balance = $this->minor($invoice->total) - $paid;
        $status = $invoice->due_date->isPast() ? 'overdue' : ($paid > 0 ? 'partially_paid' : 'issued');
        $invoice->update(['amount_paid' => $this->format($paid), 'balance' => $this->format($balance), 'status' => $status, 'paid_at' => null]);
    }

    private function minor(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    private function format(int $value): string
    {
        return number_format($value / 100, 2, '.', '');
    }
}
