<?php

namespace App\Domain\Finance\Services;

use App\Domain\Licensing\Services\PaidLicenseService;
use App\Models\Customer;
use App\Models\CustomerCreditEntry;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CustomerCreditService
{
    public static function minor(string $value): int
    {
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $amount = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$amount : $amount;
    }

    public static function money(int $amount): string
    {
        return ($amount < 0 ? '-' : '').intdiv(abs($amount), 100).'.'.str_pad((string) (abs($amount) % 100), 2, '0', STR_PAD_LEFT);
    }

    public function balance(int $customerId, string $currency): int
    {
        return CustomerCreditEntry::query()->where('customer_id', $customerId)->where('currency', $currency)
            ->get()->sum(fn ($entry) => self::minor($entry->amount));
    }

    public function apply(Invoice $invoice, User $actor): int
    {
        return DB::transaction(function () use ($invoice, $actor): int {
            Customer::query()->lockForUpdate()->findOrFail($invoice->customer_id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($invoice->status->value, ['issued', 'partially_paid', 'overdue'], true)) {
                return 0;
            }
            $amount = min(max(0, $this->balance($invoice->customer_id, $invoice->currency)), self::minor($invoice->balance));
            if ($amount < 1) {
                return 0;
            }
            $entry = CustomerCreditEntry::query()->create([
                'customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id,
                'reference' => 'invoice-credit-'.Str::uuid(),
                'currency' => $invoice->currency, 'amount' => self::money(-$amount),
                'description' => 'Credit applied to '.$invoice->invoice_number, 'created_by' => $actor->id,
            ]);
            $invoice->payments()->create([
                'amount' => self::money($amount), 'currency' => $invoice->currency,
                'reference' => $entry->reference, 'method' => 'account_credit', 'status' => 'successful',
                'paid_at' => now(), 'recorded_by' => $actor->id,
            ]);
            $remaining = self::minor($invoice->balance) - $amount;
            $invoice->update([
                'amount_paid' => self::money(self::minor($invoice->amount_paid) + $amount),
                'balance' => self::money($remaining), 'status' => $remaining === 0 ? 'paid' : 'partially_paid',
                'paid_at' => $remaining === 0 ? now() : null,
            ]);
            app(PaidLicenseService::class)->complete($invoice, $actor);

            return $amount;
        });
    }
}
