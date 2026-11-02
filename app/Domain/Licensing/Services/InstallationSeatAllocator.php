<?php

namespace App\Domain\Licensing\Services;

use App\Domain\Subscriptions\Services\PlanEntitlementService;
use App\Models\LicenseActivationCode;
use App\Models\LicenseInstallation;
use App\Models\LicenseInstallationGrant;
use App\Models\SoftwareLicense;
use Illuminate\Validation\ValidationException;

final class InstallationSeatAllocator
{
    /**
     * @param  array{activation_id?: ?int, activation_code_id?: ?int}  $exclude
     */
    public function assertAvailable(SoftwareLicense $license, array $exclude = [], string $errorField = 'license'): void
    {
        if ($this->used($license, $exclude) >= $this->limit($license)) {
            throw ValidationException::withMessages([$errorField => 'The shared installation limit has been reached for this license.']);
        }
    }

    public function limit(SoftwareLicense $license): int
    {
        $subscription = $license->subscription()->with(['entitlements', 'plan.features', 'plan.limits'])->firstOrFail();
        $now = now();
        $limits = [(int) $license->max_activations];
        $policyRows = $subscription->entitlements->filter(fn ($entitlement): bool => (! $entitlement->effective_until || $entitlement->effective_until->isFuture())
            && (! $entitlement->effective_from || $entitlement->effective_from->lte($now))
            && in_array($entitlement->feature_key, ['max_installations', 'max_server_installations'], true));

        foreach ($policyRows->where('enabled', true) as $entitlement) {
            if (is_numeric($entitlement->limit_value) && (int) $entitlement->limit_value > 0) {
                $limits[] = (int) $entitlement->limit_value;
            }
        }

        if ($policyRows->isEmpty()) {
            $planEntitlements = app(PlanEntitlementService::class);
            foreach (['max_installations', 'max_server_installations'] as $key) {
                $value = $planEntitlements->limit($subscription->plan, $key);
                if (is_numeric($value) && (int) $value > 0) {
                    $limits[] = (int) $value;
                }
            }
        }

        return max(1, ...$limits);
    }

    /**
     * @param  array{activation_id?: ?int, activation_code_id?: ?int}  $exclude
     */
    public function used(SoftwareLicense $license, array $exclude = []): int
    {
        return $license->activations()
            ->where('status', 'active')
            ->when(($exclude['activation_id'] ?? null) !== null, fn ($query) => $query->whereKeyNot($exclude['activation_id']))
            ->count()
            + LicenseInstallation::query()
                ->where('software_license_id', $license->id)
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count()
            + LicenseInstallationGrant::query()
                ->where('software_license_id', $license->id)
                ->whereIn('status', ['issued', 'active'])
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count()
            + LicenseActivationCode::query()
                ->where('software_license_id', $license->id)
                ->where('status', 'issued')
                ->where('expires_at', '>', now())
                ->when(($exclude['activation_code_id'] ?? null) !== null, fn ($query) => $query->whereKeyNot($exclude['activation_code_id']))
                ->count();
    }
}
