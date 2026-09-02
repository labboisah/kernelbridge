<?php

namespace App\Domain\Finance\Services;

use App\Domain\Licensing\Services\LicenseService;
use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class FlutterwavePaymentService
{
    public function __construct(private FlutterwaveClient $client, private PaymentManager $payments, private SubscriptionService $subscriptions, private LicenseService $licenses) {}

    public function initialize(Invoice $invoice, User $actor): string
    {
        if (! in_array($invoice->status->value, ['issued', 'partially_paid', 'overdue'], true) || (float) $invoice->balance <= 0) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is not payable online.']);
        }
        $invoice->loadMissing('customer');
        $reference = 'KBT-FLW-'.Str::ulid();
        $link = $this->client->createPayment([
            'tx_ref' => $reference, 'amount' => $invoice->balance, 'currency' => $invoice->currency,
            'redirect_url' => route('payments.flutterwave.callback'),
            'customer' => ['email' => $invoice->customer->email, 'name' => $invoice->customer->name, 'phonenumber' => $invoice->customer->phone],
            'customizations' => ['title' => 'KernelBridge Invoice Payment', 'description' => $invoice->invoice_number],
            'meta' => ['invoice_uuid' => $invoice->uuid],
        ]);
        $this->payments->create([
            'customer_id' => $invoice->customer_id, 'amount' => $invoice->balance, 'currency' => $invoice->currency,
            'payment_method' => 'card', 'transaction_reference' => $reference, 'payment_date' => today(),
            'provider' => 'flutterwave', 'metadata' => ['invoice_uuid' => $invoice->uuid, 'checkout_link' => $link],
            'notes' => 'Flutterwave online payment initiated for '.$invoice->invoice_number,
        ], $actor);

        return $link;
    }

    public function verifyAndComplete(string $transactionId, ?string $expectedReference = null): Invoice
    {
        $data = $this->client->verify($transactionId);
        $reference = (string) ($data['tx_ref'] ?? $data['reference'] ?? '');
        if ($expectedReference !== null && ! hash_equals($expectedReference, $reference)) {
            throw ValidationException::withMessages(['payment' => 'The payment reference does not match.']);
        }
        $payment = Payment::query()->where('provider', 'flutterwave')->where('transaction_reference', $reference)->firstOrFail();
        $invoice = Invoice::query()->where('uuid', data_get($payment->metadata, 'invoice_uuid'))->firstOrFail();
        if (($data['status'] ?? null) !== 'successful' || strtoupper((string) ($data['currency'] ?? '')) !== $invoice->currency || $this->minor((string) ($data['amount'] ?? 0)) !== $this->minor($payment->amount)) {
            throw ValidationException::withMessages(['payment' => 'Flutterwave could not verify the expected amount and currency.']);
        }
        if ($payment->status->value === 'verified') {
            return $invoice->refresh();
        }

        return DB::transaction(function () use ($payment, $invoice, $data, $transactionId): Invoice {
            $actor = User::query()->findOrFail($payment->recorded_by);
            $payment->update(['provider_reference' => $transactionId, 'payment_method' => $this->method((string) ($data['payment_type'] ?? 'card'))]);
            $this->payments->verify($payment, [['invoice_id' => $invoice->id, 'amount' => $payment->amount]], $actor);
            $paidInvoice = $invoice->refresh();
            $subscription = Subscription::query()->where('invoice_id', $invoice->id)->where('status', 'pending')->first();
            if ($subscription) {
                $subscription = $this->subscriptions->activate($subscription, $paidInvoice, $actor);
                $this->licenses->issue($subscription);
            }

            return $paidInvoice;
        });
    }

    private function method(string $method): string
    {
        return match (true) {
            str_contains($method, 'bank'), str_contains($method, 'transfer') => 'bank_transfer', str_contains($method, 'card') => 'card', default => 'other'
        };
    }

    private function minor(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
