<?php

namespace App\Domain\Licensing\Services;

use App\Models\HostingSetting;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;

final class ImplementationPricing
{
    public function selection(Plan $plan, array $input): array
    {
        if (($input['mode'] ?? 'local') !== 'online') {
            return ['mode' => 'local'];
        }
        $settings = HostingSetting::findOrFail(1);
        $months = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12][$plan->billing_cycle->value] ?? 0;
        if (! $settings->enabled || $settings->currency !== $plan->currency || $months === 0 || (float) $settings->monthly_price <= 0) {
            throw ValidationException::withMessages(['implementation.mode' => 'Online implementation is unavailable for this billing currency or period.']);
        }

        return ['mode' => 'online', 'monthly_price' => $settings->monthly_price, 'currency' => $settings->currency, 'months' => $months, 'domain' => $input['domain'] ?? null];
    }

    public function lines(array $selection, int $productId): array
    {
        if (($selection['mode'] ?? 'local') !== 'online') {
            return [];
        }

        return [['product_id' => $productId, 'plan_id' => null, 'description' => 'Online implementation - domain and VPS ('.$selection['months'].' months prepaid)', 'quantity' => $selection['months'], 'unit_price' => $selection['monthly_price'], 'discount' => 0, 'tax' => 0]];
    }
}
