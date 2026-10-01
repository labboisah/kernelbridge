<?php

namespace App\Domain\Licensing\Services;

use App\Domain\Finance\Services\CustomerCreditService as Credit;
use App\Domain\Finance\Services\InvoiceManager;
use App\Domain\Subscriptions\Services\EntitlementService;
use App\Models\Customer;
use App\Models\CustomerCreditEntry;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPackageOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PackageChangeService
{
    public function __construct(private PackageCatalog $catalog, private InvoiceManager $invoices, private Credit $credits) {}

    public function pending(Subscription $subscription): Builder
    {
        return SubscriptionPackageOrder::query()->where('subscription_id', $subscription->id)->where('status', 'pending')
            ->whereHas('invoice', fn ($q) => $q->where('status', '!=', 'cancelled'));
    }

    public function preview(Subscription $subscription, array $uuids, bool $renew = false): array
    {
        if (! data_get($subscription->metadata, 'selected_packages')
            || ! in_array($subscription->status->value, $renew ? ['active', 'expired', 'grace'] : ['active'], true)
            || (! $renew && (! $subscription->ends_at?->isFuture() || ! $subscription->starts_at))) {
            throw ValidationException::withMessages(['packages' => 'Renew an expired subscription before changing its packages.']);
        }
        if ($this->pending($subscription)->exists()) {
            throw ValidationException::withMessages(['packages' => 'Settle or cancel the pending package invoice first.']);
        }
        if ($renew && $subscription->ends_at?->isFuture()) {
            throw ValidationException::withMessages(['packages' => 'Renewal is available when this billing period ends.']);
        }
        $old = collect(data_get($subscription->metadata, 'package_prices', []))->keyBy('uuid');
        if ($renew && collect($uuids)->sort()->values()->all() !== $old->keys()->sort()->values()->all()) {
            throw ValidationException::withMessages(['packages' => 'Renew the current packages first, then change the selection.']);
        }
        $plans = Plan::query()->whereIn('uuid', $uuids)->where('product_id', $subscription->product_id)->get()->keyBy('uuid');
        $rows = [];
        foreach ($uuids as $uuid) {
            $plan = $plans->get($uuid);
            if (! $plan || ! array_key_exists($plan->slug, config('mediflow.packages'))) {
                throw ValidationException::withMessages(['packages' => 'Select packages from this product.']);
            }
            if ((! $old->has($uuid) || $renew) && (! $plan->is_active || ! $plan->is_public || $plan->price === null
                || Credit::minor($plan->price) <= 0 || $plan->currency !== $subscription->currency || $plan->billing_cycle !== $subscription->billing_cycle)) {
                throw ValidationException::withMessages(['packages' => 'New packages need a price and the same currency and billing period.']);
            }
            $rows[] = ['uuid' => $uuid, 'plan_id' => $plan->id, 'key' => $plan->slug, 'name' => $plan->name,
                'configuration' => $old->has($uuid) && isset($old[$uuid]['configuration']) ? $old[$uuid]['configuration'] : ($old->has($uuid) ? $this->catalog->describe($plan->slug) : $this->catalog->plan($plan)),
                'price' => (! $renew && $old->has($uuid)) ? $old[$uuid]['price'] : $plan->price, 'setup_fee' => $plan->setup_fee];
        }
        $added = array_values(array_filter($rows, fn ($row) => ! $old->has($row['uuid'])));
        $removed = $old->except($uuids)->values()->all();
        $credit = $renew ? 0 : array_sum(array_map(fn ($row) => $this->prorate($row['price'], $subscription), $removed));
        $lines = [];
        foreach ($renew ? $rows : $added as $row) {
            $amount = $renew ? Credit::minor($row['price']) : $this->prorate($row['price'], $subscription);
            if ($amount > 0) {
                $lines[] = $this->line($subscription, $row, $row['name'].($renew ? ' - renewal' : ' - remaining billing period'), Credit::money($amount));
            }
            if (! $renew && Credit::minor($row['setup_fee']) > 0) {
                $lines[] = $this->line($subscription, $row, $row['name'].' - setup', $row['setup_fee']);
            }
        }

        if ($renew) {
            $lines = array_merge($lines, app(ImplementationPricing::class)->lines(data_get($subscription->metadata, 'implementation', []), $subscription->product_id));
        }

        $vatRate = \App\Models\BillingSetting::vatRate();
        $totals = app(\App\Domain\Sales\Services\QuotationCalculator::class)->calculate($lines, $vatRate);
        return ['rows' => $rows, 'added' => $added, 'removed' => $removed, 'items' => $lines,
            'quoted_at' => now()->toISOString(), 'currency' => $subscription->currency,
            'charge' => $totals['total'], 'subtotal' => $totals['subtotal'], 'vat' => $totals['tax'], 'vat_rate' => $vatRate,
            'credit' => Credit::money($credit), 'available_credit' => Credit::money($this->credits->balance($subscription->customer_id, $subscription->currency)),
            'period_ends_at' => $subscription->ends_at?->toISOString(),
            'configuration' => ['packages' => array_map(fn ($row) => $row['configuration'], $rows)]];
    }

    public function change(Subscription $subscription, array $uuids, User $actor, bool $useCredit, bool $renew = false): ?Invoice
    {
        return DB::transaction(function () use ($subscription, $uuids, $actor, $useCredit, $renew): ?Invoice {
            Customer::query()->lockForUpdate()->findOrFail($subscription->customer_id);
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $quote = $this->preview($subscription, $uuids, $renew);
            if (! $renew && ! $quote['added'] && ! $quote['removed']) {
                throw ValidationException::withMessages(['packages' => 'Choose a different package selection.']);
            }
            if (! $renew && $quote['removed']) {
                $remaining = array_values(array_filter($quote['rows'], fn ($row) => ! in_array($row['uuid'], array_column($quote['added'], 'uuid'), true)));
                $this->applyRows($subscription, $remaining);
                if (Credit::minor($quote['credit']) > 0) {
                    CustomerCreditEntry::query()->create(['customer_id' => $subscription->customer_id, 'subscription_id' => $subscription->id,
                        'reference' => 'package-removal-'.Str::uuid(), 'currency' => $subscription->currency, 'amount' => $quote['credit'],
                        'description' => 'Unused package time: '.implode(', ', array_column($quote['removed'], 'key')), 'created_by' => $actor->id]);
                }
                $this->audit($subscription, 'packages_removed', $actor, $quote['credit']);
                if (! $remaining && ! $quote['added']) {
                    $subscription->update(['status' => 'cancelled', 'auto_renew' => false]);
                }
            }
            if (! $renew && ! $quote['added']) {
                return null;
            }
            $order = SubscriptionPackageOrder::query()->create(['subscription_id' => $subscription->id, 'kind' => $renew ? 'renewal' : 'addition',
                'snapshot' => ['rows' => $quote['rows']], 'quoted_at' => $quote['quoted_at'], 'created_by' => $actor->id]);
            if (! $quote['items']) {
                $this->fulfil($order, $actor);

                return null;
            }
            $invoice = $this->invoices->create(['customer_id' => $subscription->customer_id, 'issue_date' => today(),
                'due_date' => $renew ? today()->addDays(7) : today()->addDays(7)->min($subscription->ends_at),
                'currency' => $subscription->currency, 'items' => $quote['items'],
                'notes' => $renew ? 'MediFlow subscription renewal.' : 'Prorated package additions.',
                'terms' => 'New access starts after full settlement. Removed packages stop immediately and unused time becomes credit. Refresh the client subscription after payment.'], $actor);
            $order->update(['invoice_id' => $invoice->id]);
            $this->invoices->issue($invoice, $actor);
            if ($useCredit) {
                $this->credits->apply($invoice, $actor);
            }

            return $invoice->refresh();
        });
    }

    public function fulfil(SubscriptionPackageOrder $order, User $actor): void
    {
        $order = SubscriptionPackageOrder::query()->lockForUpdate()->findOrFail($order->id);
        if ($order->status !== 'pending') {
            return;
        }
        $subscription = Subscription::query()->lockForUpdate()->findOrFail($order->subscription_id);
        if ($order->invoice_id) {
            $invoice = Invoice::query()->findOrFail($order->invoice_id);
            if ($invoice->status->value !== 'paid' || Credit::minor($invoice->balance) !== 0
                || $invoice->customer_id !== $subscription->customer_id || $invoice->currency !== $subscription->currency) {
                return;
            }
        }
        if (($order->kind === 'addition' && (! $subscription->ends_at?->isFuture() || $subscription->status->value !== 'active'))
            || ($order->kind === 'renewal' && ! in_array($subscription->status->value, ['active', 'expired', 'grace'], true))) {
            $amount = $order->invoice_id ? $order->invoice->total : '0.00';
            if (Credit::minor($amount) > 0) {
                CustomerCreditEntry::query()->firstOrCreate(['reference' => 'late-package-order-'.$order->uuid], [
                    'customer_id' => $subscription->customer_id, 'subscription_id' => $subscription->id, 'invoice_id' => $order->invoice_id,
                    'currency' => $subscription->currency, 'amount' => $amount,
                    'description' => 'Package payment received after the usable billing period.', 'created_by' => $actor->id]);
            }
            $order->update(['status' => 'credited', 'applied_at' => now()]);

            return;
        }
        if ($order->kind === 'renewal') {
            $start = now();
            $end = match ($subscription->billing_cycle->value) {
                'monthly' => $start->copy()->addMonthNoOverflow(), 'quarterly' => $start->copy()->addMonthsNoOverflow(3),
                'semiannual' => $start->copy()->addMonthsNoOverflow(6), 'annual' => $start->copy()->addYearNoOverflow(),
            };
            $subscription->update(['starts_at' => $start, 'ends_at' => $end, 'status' => 'active', 'invoice_id' => $order->invoice_id,
                'grace_ends_at' => null, 'renewal_notice_sent_at' => null]);
            $subscription->license()->where('status', 'active')->update(['expires_at' => $end]);
        }
        $this->applyRows($subscription, $order->snapshot['rows']);
        $order->update(['status' => 'applied', 'applied_at' => now()]);
        app(\App\Domain\Partners\CommissionService::class)->accrue($order->invoice);
        $this->audit($subscription, $order->kind === 'renewal' ? 'packages_renewed' : 'packages_added', $actor);
    }

    public function cancel(SubscriptionPackageOrder $order, User $actor): void
    {
        DB::transaction(function () use ($order, $actor): void {
            $subscription = Subscription::query()->findOrFail($order->subscription_id);
            Customer::query()->lockForUpdate()->findOrFail($subscription->customer_id);
            $order = SubscriptionPackageOrder::query()->lockForUpdate()->findOrFail($order->id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($order->invoice_id);
            if ($order->status !== 'pending' || Credit::minor($invoice->amount_paid) > 0) {
                throw ValidationException::withMessages(['packages' => 'Only an unpaid pending change can be cancelled.']);
            }
            $this->invoices->cancel($invoice, $actor);
            $order->update(['status' => 'cancelled']);
            if (! data_get($subscription->metadata, 'selected_packages')) {
                $subscription->update(['status' => 'cancelled', 'auto_renew' => false]);
            }
        });
    }

    private function applyRows(Subscription $subscription, array $rows): void
    {
        $metadata = $subscription->metadata;
        $keys = array_column($rows, 'key');
        $metadata['selected_packages'] = $keys;
        $metadata['package_names'] = array_column($rows, 'name');
        $metadata['package_prices'] = $rows;
        $metadata['primary_package'] = in_array($metadata['primary_package'] ?? null, $keys, true) ? $metadata['primary_package'] : ($keys[0] ?? null);
        $metadata['licensed_modules'] = array_values(array_unique(array_merge([], ...array_map(fn ($row) => array_column($row['configuration']['features'], 'key'), $rows))));
        $metadata['configuration_revision'] = ($metadata['configuration_revision'] ?? 1) + 1;
        $primary = collect($rows)->firstWhere('key', $metadata['primary_package']);
        $subscription->update(['metadata' => $metadata, 'plan_id' => $primary['plan_id'] ?? $subscription->plan_id,
            'price' => Credit::money(array_sum(array_map(fn ($row) => Credit::minor($row['price']), $rows)))]);
        app(EntitlementService::class)->snapshot($subscription, $subscription->plan()->firstOrFail());
    }

    private function prorate(string $price, Subscription $subscription): int
    {
        $total = max(1, $subscription->ends_at->getTimestamp() - $subscription->starts_at->getTimestamp());
        $remaining = min($total, max(0, $subscription->ends_at->getTimestamp() - now()->getTimestamp()));

        return min(Credit::minor($price), (int) round(Credit::minor($price) * ($remaining / $total)));
    }

    private function line(Subscription $subscription, array $row, string $description, string $amount): array
    {
        return ['product_id' => $subscription->product_id, 'plan_id' => $row['plan_id'], 'description' => $description,
            'quantity' => 1, 'unit_price' => $amount, 'discount' => 0, 'tax' => 0];
    }

    private function audit(Subscription $subscription, string $type, User $actor, string $credit = '0.00'): void
    {
        $subscription->changes()->create(['change_type' => $type, 'from_plan_id' => $subscription->plan_id, 'to_plan_id' => $subscription->plan_id,
            'credit' => $credit, 'debit' => 0, 'net_amount' => Credit::money(-Credit::minor($credit)), 'before' => [],
            'after' => ['packages' => $subscription->metadata['selected_packages']], 'reason' => 'Customer package selection',
            'changed_by' => $actor->id, 'effective_at' => now()]);
    }
}
