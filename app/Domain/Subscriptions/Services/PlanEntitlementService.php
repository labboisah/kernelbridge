<?php

namespace App\Domain\Subscriptions\Services;

use App\Models\Plan;

final class PlanEntitlementService
{
    public function contains(Plan $plan, string $featureKey): bool
    {
        return (bool) $plan->features()->where('feature_key', $featureKey)->value('enabled');
    }

    public function limit(Plan $plan, string $key): int|float|string|null
    {
        $value = $plan->limits()->where('limit_key', $key)->value('limit_value')
            ?? $plan->features()->where('feature_key', $key)->where('enabled', true)->value('limit_value');

        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? $value + 0 : $value;
    }
}
