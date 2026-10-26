<?php

namespace App\Domain\Licensing\Services;

use App\Models\ApiClient;
use App\Models\LicenseBusinessClaim;
use App\Models\LicenseActivation;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LicenseService
{
    public function claim(ApiClient $client, string $activationCode, string $businessUuid): array
    {
        return DB::transaction(function () use ($client, $activationCode, $businessUuid): array {
            $license = $this->resolve($client, $activationCode, true);
            $claim = LicenseBusinessClaim::query()
                ->where('software_license_id', $license->id)
                ->lockForUpdate()
                ->first();

            if ($claim && $claim->business_uuid !== $businessUuid) {
                throw ValidationException::withMessages([
                    'activation_code' => 'This license has already been claimed by another business.',
                ]);
            }

            $businessClaim = LicenseBusinessClaim::query()
                ->where('product_id', $client->product_id)
                ->where('business_uuid', $businessUuid)
                ->lockForUpdate()
                ->first();

            if ($businessClaim && $businessClaim->software_license_id !== $license->id) {
                throw ValidationException::withMessages([
                    'business_uuid' => 'This business already has a claim for another license.',
                ]);
            }

            if (! $claim) {
                LicenseBusinessClaim::query()->create([
                    'software_license_id' => $license->id,
                    'product_id' => $client->product_id,
                    'business_uuid' => $businessUuid,
                ]);
            }

            return $this->businessClaimPayload($license);
        });
    }

    public function issue(Subscription $subscription, int $maxActivations = 1): array
    {
        return DB::transaction(function () use ($subscription, $maxActivations): array {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if (! in_array($subscription->status->value, ['trial', 'active', 'grace'], true)) {
                throw ValidationException::withMessages(['subscription' => 'Only a trial, active, or grace-period subscription can receive a license.']);
            }
            if ($maxActivations < 1) {
                throw ValidationException::withMessages(['max_activations' => 'At least one activation is required.']);
            }
            $existing = SoftwareLicense::query()->where('subscription_id', $subscription->id)->where('status', 'active')->first();
            if ($existing) {
                return ['license' => $existing, 'license_key' => $this->key($existing)];
            }

            $key = 'KBT-'.strtoupper(Str::random(8).'-'.Str::random(8).'-'.Str::random(8));
            $license = SoftwareLicense::query()->create(['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id, 'product_id' => $subscription->product_id, 'key_lookup' => $this->lookup($key), 'key_hash' => Hash::make($key), 'status' => 'active', 'max_activations' => $maxActivations, 'expires_at' => $subscription->ends_at, 'metadata' => ['encrypted_license_key' => Crypt::encryptString($key)]]);

            return ['license' => $license, 'license_key' => $key];
        });
    }

    public function key(SoftwareLicense $license): ?string
    {
        $encrypted = data_get($license->metadata, 'encrypted_license_key');

        return is_string($encrypted) && $encrypted !== '' ? Crypt::decryptString($encrypted) : null;
    }

    public function cacheSigningKey(SoftwareLicense $license): string
    {
        $encrypted = data_get($license->metadata, 'encrypted_cache_signing_key');
        if (is_string($encrypted) && $encrypted !== '') {
            return Crypt::decryptString($encrypted);
        }

        $key = Str::random(64);
        $metadata = $license->metadata ?? [];
        $metadata['encrypted_cache_signing_key'] = Crypt::encryptString($key);
        $license->forceFill(['metadata' => $metadata])->save();

        return $key;
    }

    public function activate(ApiClient $client, array $data, string $ip): array
    {
        return DB::transaction(function () use ($client, $data, $ip): array {
            $license = $this->resolve($client, $data['license_key'], true);
            $fingerprint = $this->fingerprint($data['device_id']);
            $activation = $license->activations()->where('device_fingerprint', $fingerprint)->first();
            $bound = (bool) data_get($license->subscription->metadata, 'device_bound', false);
            if ($bound && empty($data['machine_fingerprint'])) {
                throw ValidationException::withMessages(['machine_fingerprint' => 'A machine fingerprint is required to activate this licence. Update the licensing client.']);
            }
            if ($bound && ! $activation && $license->activations()->exists()) {
                throw ValidationException::withMessages(['device_id' => 'This licence is permanently bound to another installation. Contact support for a device replacement.']);
            }
            if ($activation) {
                $this->assertMachine($activation, $data);
            }
            if ($activation?->status !== 'active' && $license->activations()->where('status', 'active')->count() >= $license->max_activations) {
                throw ValidationException::withMessages(['license_key' => 'Maximum activations reached.']);
            }
            $metadata = $activation?->metadata ?? [];
            if (! empty($data['machine_fingerprint'])) {
                $metadata['machine_hash'] = $this->fingerprint($data['machine_fingerprint']);
            }
            if (isset($data['device_info'])) {
                $metadata['device_info'] = $data['device_info'];
            }
            $values = [
                'device_name' => $data['device_name'] ?? $activation?->device_name,
                'status' => 'active', 'deactivated_at' => null, 'last_verified_at' => now(),
                'ip_address' => $ip, 'metadata' => $metadata,
            ];
            if ($activation) {
                $activation->update($values);
            } else {
                $activation = $license->activations()->create([
                    ...$values, 'device_fingerprint' => $fingerprint, 'activated_at' => now(),
                ]);
            }

            return $this->payload($license, $activation);
        });
    }

    public function verify(ApiClient $client, array $data): array
    {
        return DB::transaction(function () use ($client, $data): array {
            $license = $this->resolve($client, $data['license_key'], true);
            $activation = $license->activations()->where('device_fingerprint', $this->fingerprint($data['device_id']))->where('status', 'active')->first();
            if (! $activation) {
                throw ValidationException::withMessages(['device_id' => 'No active license activation exists for this device.']);
            }
            $this->assertMachine($activation, $data);
            $activation->update(['last_verified_at' => now()]);

            return $this->payload($license, $activation);
        });
    }

    public function deactivate(ApiClient $client, array $data): array
    {
        return DB::transaction(function () use ($client, $data): array {
            $license = $this->resolve($client, $data['license_key'], true);
            $activation = $license->activations()->where('device_fingerprint', $this->fingerprint($data['device_id']))->where('status', 'active')->first();
            if (! $activation) {
                throw ValidationException::withMessages(['device_id' => 'No active license activation exists for this device.']);
            }
            $this->assertMachine($activation, $data);
            $activation->update(['status' => 'deactivated', 'deactivated_at' => now()]);

            return ['license_uuid' => $license->uuid, 'activation_uuid' => $activation->uuid, 'status' => 'deactivated'];
        });
    }

    private function assertMachine(LicenseActivation $activation, array $data): void
    {
        $expected = data_get($activation->metadata, 'machine_hash');
        if ($expected && (empty($data['machine_fingerprint']) || ! hash_equals($expected, $this->fingerprint($data['machine_fingerprint'])))) {
            throw ValidationException::withMessages(['device_id' => 'The licence belongs to a different computer.']);
        }
    }

    private function resolve(ApiClient $client, string $key, bool $lock = false): SoftwareLicense
    {
        $license = SoftwareLicense::query()->with(['subscription', 'activations'])->where('product_id', $client->product_id)->when($client->subscription_id, fn ($q) => $q->where('subscription_id', $client->subscription_id))->where('key_lookup', $this->lookup($key))->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $license || ! Hash::check($key, $license->key_hash) || $license->status !== 'active' || ($license->expires_at && $license->expires_at->isPast()) || ($license->subscription->starts_at?->isFuture()) || ($license->subscription->ends_at?->isPast() && ! ($license->subscription->status->value === 'grace' && $license->subscription->grace_ends_at?->isFuture())) || ! in_array($license->subscription->status->value, ['trial', 'active', 'grace'], true)) {
            throw ValidationException::withMessages(['license_key' => 'License is invalid or inactive.']);
        }

        return $license;
    }

    private function payload(SoftwareLicense $license, $activation): array
    {
        $subscription = $license->subscription;
        $configuration = app(PackageCatalog::class)->configuration($subscription);

        return [
            'license_uuid' => $license->uuid, 'activation_uuid' => $activation->uuid, 'status' => 'active',
            'expires_at' => $license->expires_at?->toISOString(), 'subscription_uuid' => $subscription->uuid,
            'selected_packages' => data_get($subscription->metadata, 'selected_packages', []),
            'primary_package' => data_get($subscription->metadata, 'primary_package'),
            'configuration' => $configuration,
            'subscription' => [
                'uuid' => $subscription->uuid, 'status' => $subscription->status->value,
                'starts_at' => $subscription->starts_at?->toISOString(), 'ends_at' => $subscription->ends_at?->toISOString(),
                'grace_ends_at' => $subscription->grace_ends_at?->toISOString(),
                'primary_package' => $configuration['primary_package'],
                'selected_packages' => data_get($subscription->metadata, 'selected_packages', []),
                'configuration' => $configuration,
            ],
            'entitlements' => ['entitlements' => $subscription->entitlements()->whereNull('effective_until')->get()
                ->map(fn ($item) => ['key' => $item->feature_key, 'name' => $item->feature_name, 'enabled' => $item->enabled, 'limit' => $item->limit_value])->all()],
        ];
    }

    private function businessClaimPayload(SoftwareLicense $license): array
    {
        $subscription = $license->subscription;

        return [
            'product_code' => strtoupper((string) $license->product()->value('code')),
            'license_uuid' => $license->uuid,
            'subscription_uuid' => $subscription->uuid,
            'status' => 'active',
            'expires_at' => $license->expires_at?->toISOString(),
            'grace_expires_at' => $subscription->grace_ends_at?->toISOString(),
            'entitlements' => $subscription->entitlements()
                ->whereNull('effective_until')
                ->orderBy('feature_key')
                ->get()
                ->map(fn ($entitlement) => [
                    'key' => $entitlement->feature_key,
                    'name' => $entitlement->feature_name,
                    'enabled' => $entitlement->enabled,
                    'limit' => $entitlement->limit_value,
                ])
                ->all(),
        ];
    }

    private function lookup(string $key): string
    {
        return hash_hmac('sha256', strtoupper(trim($key)), (string) config('app.key'));
    }

    private function fingerprint(string $device): string
    {
        return hash_hmac('sha256', $device, (string) config('app.key'));
    }
}
