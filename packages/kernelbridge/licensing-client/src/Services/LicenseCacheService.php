<?php

namespace KernelBridge\LicensingClient\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KernelBridge\LicensingClient\Models\LicenseState;

final class LicenseCacheService
{
    public function state(): LicenseState
    {
        $productCode = strtoupper((string) config('kernelbridge-licensing.product_code'));
        if ($productCode === '') {
            throw new \LogicException('KERNELBRIDGE_PRODUCT_CODE is required.');
        }

        return LicenseState::query()->firstOrCreate(
            ['product_code' => $productCode],
            ['installation_identifier' => (string) Str::uuid(), 'status' => 'unlicensed'],
        );
    }

    public function storeVerified(string $licenseKey, array $license, array $subscription, array $entitlements): LicenseState
    {
        return DB::transaction(function () use ($licenseKey, $license, $subscription, $entitlements): LicenseState {
            $state = LicenseState::query()->lockForUpdate()->findOrFail($this->state()->id);
            $verifiedAt = now();
            $expiresAt = $this->expiry($license, $subscription);
            $grace = $verifiedAt->copy()->addHours(max(1, (int) config('kernelbridge-licensing.offline_grace_hours', 72)));
            if ($expiresAt && $grace->gt($expiresAt)) {
                $grace = $expiresAt->copy();
            }
            $payload = [
                'version' => 1,
                'product_code' => $state->product_code,
                'license_identifier' => $license['license_uuid'],
                'subscription_identifier' => $license['subscription_uuid'],
                'subscription' => $subscription,
                'features' => collect($entitlements['entitlements'] ?? [])->mapWithKeys(fn (array $item): array => [(string) $item['key'] => ['enabled' => (bool) ($item['enabled'] ?? false), 'limit' => $item['limit'] ?? null]])->all(),
                'verified_at' => $verifiedAt->toISOString(),
                'offline_grace_expires_at' => $grace->toISOString(),
            ];
            $state->update([
                'license_identifier' => $license['license_uuid'],
                'subscription_identifier' => $license['subscription_uuid'],
                'activation_identifier' => $license['activation_uuid'] ?? $state->activation_identifier,
                'encrypted_license_key' => $licenseKey,
                'status' => $subscription['status'] ?? $license['status'] ?? 'inactive',
                'last_successful_verification_at' => $verifiedAt,
                'expires_at' => $expiresAt,
                'offline_grace_expires_at' => $grace,
                'entitlement_payload' => $payload,
                'entitlement_signature' => $this->sign($payload),
                'last_error_code' => null,
            ]);

            return $state->refresh();
        });
    }

    public function markRejected(string $code): void
    {
        $this->state()->update(['status' => 'invalid', 'last_error_code' => $code]);
    }

    public function markDeactivated(): void
    {
        $this->state()->update(['status' => 'deactivated', 'offline_grace_expires_at' => now(), 'last_error_code' => null]);
    }

    public function hasUsableLicense(): bool
    {
        $state = $this->state();

        return in_array($state->status, ['active', 'trial', 'grace'], true)
            && $state->last_successful_verification_at !== null
            && ($state->expires_at === null || $state->expires_at->isFuture())
            && $state->offline_grace_expires_at?->isFuture()
            && $this->signatureIsValid($state);
    }

    public function hasFeature(string $feature): bool
    {
        return $this->hasUsableLicense() && (bool) ($this->state()->entitlement_payload['features'][$feature]['enabled'] ?? false);
    }

    public function limit(string $feature): int|float|string|null
    {
        if (! $this->hasFeature($feature)) {
            return 0;
        }

        return $this->state()->entitlement_payload['features'][$feature]['limit'] ?? null;
    }

    public function signatureIsValid(?LicenseState $state = null): bool
    {
        $state ??= $this->state();
        if (! is_array($state->entitlement_payload) || ! $state->entitlement_signature) {
            return false;
        }

        return hash_equals($state->entitlement_signature, $this->sign($state->entitlement_payload));
    }

    private function expiry(array $license, array $subscription): ?Carbon
    {
        $value = $license['expires_at'] ?? $subscription['grace_ends_at'] ?? $subscription['ends_at'] ?? null;
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value);
    }

    private function sign(array $payload): string
    {
        $key = (string) config('kernelbridge-licensing.signature_key');
        if ($key === '') {
            throw new \LogicException('KERNELBRIDGE_CACHE_SIGNING_KEY or APP_KEY is required.');
        }

        return hash_hmac('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $key);
    }

    private function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
