<?php

namespace App\Domain\Subscriptions\Services;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SubscriptionService
{
    public function __construct(private SubscriptionProrationService $proration, private EntitlementService $entitlements) {}

    public function create(Customer $customer, Plan $plan, ?Invoice $invoice, User $actor, array $options = []): Subscription
    {
        return DB::transaction(function () use ($customer, $plan, $invoice, $actor, $options): Subscription {
            $price = $options['price'] ?? $plan->price;
            if ($price === null) {
                throw ValidationException::withMessages(['price' => 'A contracted price is required for custom plans.']);
            }
            $subscription = Subscription::query()->create(['customer_id' => $customer->id, 'product_id' => $plan->product_id, 'plan_id' => $plan->id, 'invoice_id' => $invoice?->id, 'status' => SubscriptionStatus::Pending, 'auto_renew' => $options['auto_renew'] ?? true, 'price' => $price, 'currency' => $options['currency'] ?? $plan->currency, 'billing_cycle' => $plan->billing_cycle, 'metadata' => $options['metadata'] ?? null, 'created_by' => $actor->id]);
            $customer->products()->syncWithoutDetaching([$plan->product_id]);
            $subscription->forceFill(['subscription_number' => sprintf('KBT-SUB-%s-%06d', now()->year, $subscription->id)])->save();
            $this->change($subscription, 'created', null, $plan, $actor, [], 'New subscription');

            return $subscription;
        });
    }

    public function activate(Subscription $subscription, Invoice $invoice, User $actor, ?CarbonInterface $startsAt = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $invoice, $actor, $startsAt): Subscription {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if ($subscription->status !== SubscriptionStatus::Pending || ! $this->matchesInvoice($subscription, $invoice)) {
                throw ValidationException::withMessages(['invoice_id' => 'A matching paid invoice is required to activate this subscription.']);
            }
            $start = $startsAt ?? now();
            $end = $this->cycleEnd($start, $subscription->billing_cycle);
            $before = $this->state($subscription);
            $subscription->update(['invoice_id' => $invoice->id, 'status' => SubscriptionStatus::Active, 'starts_at' => $start, 'ends_at' => $end, 'grace_ends_at' => null]);
            $this->entitlements->snapshot($subscription, $subscription->plan);
            $this->change($subscription, 'activated', $subscription->plan, $subscription->plan, $actor, $before, 'Paid invoice activation');

            return $subscription->refresh();
        });
    }

    public function startTrial(Subscription $subscription, User $actor, ?CarbonInterface $startsAt = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $actor, $startsAt): Subscription {
            if ($subscription->status !== SubscriptionStatus::Pending || $subscription->plan->trial_days < 1) {
                throw ValidationException::withMessages(['status' => 'This subscription is not eligible for trial.']);
            }
            $start = $startsAt ?? now();
            $before = $this->state($subscription);
            $subscription->update(['status' => SubscriptionStatus::Trial, 'starts_at' => $start, 'trial_ends_at' => $start->copy()->addDays($subscription->plan->trial_days), 'ends_at' => $start->copy()->addDays($subscription->plan->trial_days)]);
            $this->entitlements->snapshot($subscription, $subscription->plan);
            $this->change($subscription, 'trial_started', $subscription->plan, $subscription->plan, $actor, $before, 'Trial started');

            return $subscription->refresh();
        });
    }

    public function renew(Subscription $subscription, Invoice $invoice, User $actor): Subscription
    {
        return DB::transaction(function () use ($subscription, $invoice, $actor): Subscription {
            if (! $this->matchesInvoice($subscription, $invoice)) {
                throw ValidationException::withMessages(['invoice_id' => 'A matching paid renewal invoice is required.']);
            }
            $before = $this->state($subscription);
            $start = $subscription->ends_at && $subscription->ends_at->isFuture() ? $subscription->ends_at : now();
            $subscription->update(['invoice_id' => $invoice->id, 'status' => SubscriptionStatus::Active, 'starts_at' => $start, 'ends_at' => $this->cycleEnd($start, $subscription->billing_cycle), 'grace_ends_at' => null, 'price' => $subscription->plan->price, 'currency' => $subscription->plan->currency, 'renewal_notice_sent_at' => null]);
            $this->entitlements->snapshot($subscription, $subscription->plan);
            $this->change($subscription, 'renewed', $subscription->plan, $subscription->plan, $actor, $before, 'Paid renewal');

            return $subscription->refresh();
        });
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, User $actor, ?CarbonInterface $effectiveAt = null): array
    {
        return DB::transaction(function () use ($subscription, $newPlan, $actor, $effectiveAt): array {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $effectiveAt ??= now();
            if ($subscription->status !== SubscriptionStatus::Active || $newPlan->product_id !== $subscription->product_id || ! $newPlan->is_active) {
                throw ValidationException::withMessages(['plan_id' => 'An active plan for the same product is required.']);
            }
            if ($newPlan->price === null) {
                throw ValidationException::withMessages(['plan_id' => 'A custom-priced plan requires a separately negotiated subscription.']);
            }
            $oldPlan = $subscription->plan;
            $amounts = $this->proration->calculate($subscription, $newPlan, $effectiveAt);
            $before = $this->state($subscription);
            $type = (float) $newPlan->price >= (float) $subscription->price ? 'upgraded' : 'downgraded';
            $subscription->update(['plan_id' => $newPlan->id, 'price' => $newPlan->price, 'currency' => $newPlan->currency, 'billing_cycle' => $newPlan->billing_cycle]);
            $this->entitlements->snapshot($subscription, $newPlan);
            $this->change($subscription, $type, $oldPlan, $newPlan, $actor, $before, 'Plan changed', $amounts, $effectiveAt);

            return ['subscription' => $subscription->refresh(), ...$amounts];
        });
    }

    public function extend(Subscription $subscription, int $days, User $actor, string $reason): Subscription
    {
        return DB::transaction(function () use ($subscription, $days, $actor, $reason): Subscription {
            if ($days < 1) {
                throw ValidationException::withMessages(['days' => 'Extension must be at least one day.']);
            } $before = $this->state($subscription);
            $subscription->update(['ends_at' => ($subscription->ends_at ?? now())->copy()->addDays($days)]);
            $this->change($subscription, 'extended', $subscription->plan, $subscription->plan, $actor, $before, $reason);

            return $subscription->refresh();
        });
    }

    public function suspend(Subscription $subscription, User $actor, string $reason): Subscription
    {
        return $this->transition($subscription, SubscriptionStatus::Suspended, 'suspended', $actor, $reason);
    }

    public function cancel(Subscription $subscription, User $actor, string $reason): Subscription
    {
        return $this->transition($subscription, SubscriptionStatus::Cancelled, 'cancelled', $actor, $reason);
    }

    public function processLifecycle(): array
    {
        $grace = 0;
        $expired = 0;
        Subscription::query()->whereIn('status', ['active', 'trial'])->where('ends_at', '<=', now())->each(function (Subscription $subscription) use (&$grace, &$expired): void {
            $days = $subscription->plan->grace_period_days;
            $before = $this->state($subscription);
            if ($days > 0) {
                $subscription->update(['status' => SubscriptionStatus::Grace, 'grace_ends_at' => now()->addDays($days)]);
                $this->change($subscription, 'grace_entered', $subscription->plan, $subscription->plan, null, $before, 'Term ended');
                $grace++;
            } else {
                $subscription->update(['status' => SubscriptionStatus::Expired]);
                $this->change($subscription, 'expired', $subscription->plan, $subscription->plan, null, $before, 'Term ended');
                $expired++;
            }
        });
        Subscription::query()->where('status', SubscriptionStatus::Grace)->where('grace_ends_at', '<=', now())->each(function (Subscription $subscription) use (&$expired): void {
            $before = $this->state($subscription);
            $subscription->update(['status' => SubscriptionStatus::Expired]);
            $this->change($subscription, 'expired', $subscription->plan, $subscription->plan, null, $before, 'Grace period ended');
            $expired++;
        });

        return compact('grace', 'expired');
    }

    private function transition(Subscription $subscription, SubscriptionStatus $status, string $type, User $actor, string $reason): Subscription
    {
        return DB::transaction(function () use ($subscription, $status, $type, $actor, $reason): Subscription {
            if (in_array($subscription->status, [SubscriptionStatus::Expired, SubscriptionStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['status' => 'This subscription is already finalized.']);
            } $before = $this->state($subscription);
            $subscription->update(['status' => $status, 'auto_renew' => $status === SubscriptionStatus::Cancelled ? false : $subscription->auto_renew]);
            $this->change($subscription, $type, $subscription->plan, $subscription->plan, $actor, $before, $reason);

            return $subscription->refresh();
        });
    }

    private function cycleEnd(CarbonInterface $start, BillingCycle $cycle): CarbonInterface
    {
        return match ($cycle) {
            BillingCycle::Monthly => $start->copy()->addMonthNoOverflow(), BillingCycle::Quarterly => $start->copy()->addMonthsNoOverflow(3), BillingCycle::Semiannual => $start->copy()->addMonthsNoOverflow(6), BillingCycle::Annual => $start->copy()->addYearNoOverflow(), BillingCycle::OneTime, BillingCycle::Custom => throw ValidationException::withMessages(['billing_cycle' => 'An explicit term is required for this billing cycle.'])
        };
    }

    private function matchesInvoice(Subscription $subscription, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Paid
            && $invoice->customer_id === $subscription->customer_id
            && $invoice->currency === $subscription->currency
            && $invoice->items()->where('product_id', $subscription->product_id)->exists();
    }

    private function state(Subscription $subscription): array
    {
        return $subscription->only(['plan_id', 'status', 'starts_at', 'ends_at', 'price', 'currency', 'billing_cycle', 'auto_renew']);
    }

    private function change(Subscription $subscription, string $type, ?Plan $from, ?Plan $to, ?User $actor, array $before, string $reason, array $amounts = [], ?CarbonInterface $effectiveAt = null): void
    {
        $subscription->changes()->create(['change_type' => $type, 'from_plan_id' => $from?->id, 'to_plan_id' => $to?->id, 'credit' => $amounts['credit'] ?? 0, 'debit' => $amounts['debit'] ?? 0, 'net_amount' => $amounts['net_amount'] ?? 0, 'before' => $before, 'after' => $this->state($subscription), 'reason' => $reason, 'changed_by' => $actor?->id, 'effective_at' => $effectiveAt ?? now()]);
    }
}
