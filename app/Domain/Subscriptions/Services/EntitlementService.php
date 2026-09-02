<?php

namespace App\Domain\Subscriptions\Services;

use App\Models\Plan;
use App\Models\Subscription;

final class EntitlementService
{
    public function snapshot(Subscription $subscription, Plan $plan): void
    {
        $now = now();
        $subscription->entitlements()->whereNull('effective_until')->update(['effective_until' => $now]);
        $features = $plan->features()->get()->keyBy('feature_key');
        foreach ($plan->limits()->get() as $limit) {
            $features->put($limit->limit_key, (object) ['feature_key' => $limit->limit_key, 'feature_name' => $limit->limit_name, 'enabled' => true, 'limit_value' => $limit->limit_value]);
        }
        foreach ($features as $feature) {
            $subscription->entitlements()->create(['feature_key' => $feature->feature_key, 'feature_name' => $feature->feature_name, 'enabled' => $feature->enabled, 'limit_value' => $feature->limit_value, 'source_plan_id' => $plan->id, 'effective_from' => $now]);
        }
    }

    public function contains(Subscription $subscription, string $featureKey): bool
    {
        if (! in_array($subscription->status->value, ['trial', 'active', 'grace'], true)) {
            return false;
        }

        return (bool) $subscription->entitlements()->where('feature_key', $featureKey)->whereNull('effective_until')->value('enabled');
    }

    public function limit(Subscription $subscription, string $featureKey): int|float|string|null
    {
        if (! $this->contains($subscription, $featureKey)) {
            return null;
        }
        $value = $subscription->entitlements()->where('feature_key', $featureKey)->whereNull('effective_until')->value('limit_value');

        return is_numeric($value) ? $value + 0 : $value;
    }
}
