<?php

namespace App\Domain\Partners;

use App\Enums\PaymentStatus;
use App\Models\BillingSetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Lead;
use App\Models\PartnerPointEntry;
use App\Models\Payment;
use App\Models\ProspectiveCustomer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PartnerPointsService
{
    public function awardProspect(ProspectiveCustomer $prospect, string $event, int $points): void
    {
        DB::transaction(function () use ($prospect, $event, $points): void {
            $partner = User::query()->whereKey($prospect->partner_user_id)->lockForUpdate()->firstOrFail();
            if (! $partner->isApprovedAgent()) {
                return;
            }
            PartnerPointEntry::query()->firstOrCreate(
                ['prospective_customer_id' => $prospect->id, 'event_type' => $event],
                ['partner_user_id' => $prospect->partner_user_id, 'points' => $points],
            );
        });
    }

    public function awardWonLead(Lead $lead): void
    {
        if (! $lead->partner_user_id) {
            return;
        }

        DB::transaction(function () use ($lead): void {
            $partner = User::query()->whereKey($lead->partner_user_id)->lockForUpdate()->firstOrFail();
            if (! $partner->isApprovedAgent()) {
                return;
            }
            PartnerPointEntry::query()->firstOrCreate(
                ['lead_id' => $lead->id, 'event_type' => 'opportunity_won'],
                ['partner_user_id' => $lead->partner_user_id, 'points' => 10],
            );
        });
    }

    public function redeemOnVerifiedPayment(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Verified || $payment->currency !== 'NGN') {
            return;
        }

        $customer = Customer::query()->findOrFail($payment->customer_id);

        $this->redeem($customer->partner_user_id, $payment->amount, $payment->currency, $payment->id);
    }

    public function redeemOnSuccessfulInvoicePayment(InvoicePayment $payment): void
    {
        if ($payment->status !== 'successful' || $payment->currency !== 'NGN') {
            return;
        }

        $invoice = Invoice::query()->with('customer')->findOrFail($payment->invoice_id);
        $this->redeem($invoice->customer->partner_user_id, $payment->amount, $payment->currency, null, $payment->id);
    }

    public function reverseRedemption(Payment $payment): void
    {
        PartnerPointEntry::query()
            ->where('payment_id', $payment->id)
            ->where('event_type', 'payment_redemption')
            ->where('status', 'earned')
            ->update(['status' => 'reversed']);
    }

    private function minor(string|int|float $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function money(int $value): string
    {
        return number_format($value / 100, 2, '.', '');
    }

    private function redeem(?int $partnerId, string $amount, string $currency, ?int $paymentId = null, ?int $invoicePaymentId = null): void
    {
        if (! $partnerId || $currency !== 'NGN') {
            return;
        }

        DB::transaction(function () use ($partnerId, $amount, $paymentId, $invoicePaymentId): void {
            $partner = User::query()->whereKey($partnerId)->lockForUpdate()->firstOrFail();
            if (! $partner->isApprovedAgent()) {
                return;
            }
            if (PartnerPointEntry::query()->where('event_type', 'payment_redemption')
                ->where(function ($query) use ($paymentId, $invoicePaymentId): void {
                    $query->when($paymentId !== null, fn ($query) => $query->where('payment_id', $paymentId))
                        ->when($invoicePaymentId !== null, fn ($query) => $query->orWhere('invoice_payment_id', $invoicePaymentId));
                })->exists()) {
                return;
            }

            $balance = (int) PartnerPointEntry::query()->where('partner_user_id', $partnerId)->where('status', 'earned')->sum('points');
            $pointValue = BillingSetting::findOrFail(1)->partner_point_value;
            $pointValueMinor = $this->minor($pointValue);
            $redeemedPoints = min($balance, intdiv($this->minor($amount), $pointValueMinor));
            if ($redeemedPoints <= 0) {
                return;
            }

            PartnerPointEntry::query()->create([
                'partner_user_id' => $partnerId,
                'payment_id' => $paymentId,
                'invoice_payment_id' => $invoicePaymentId,
                'event_type' => 'payment_redemption',
                'points' => -$redeemedPoints,
                'point_value' => $pointValue,
                'amount' => $this->money($redeemedPoints * $pointValueMinor),
                'currency' => 'NGN',
            ]);
        });
    }
}
