<?php

namespace App\Domain\Licensing\Services;

use App\Models\ApiClient;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LicenseService
{
    public function issue(Subscription $subscription, int $maxActivations = 1): array
    {
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
        $license = $this->resolve($client, $data['license_key']);
        $fingerprint = $this->fingerprint($data['device_id']);
        $activation = $license->activations()->where('device_fingerprint', $fingerprint)->first();
        if ($activation?->status === 'active') {
            return $this->payload($license, $activation);
        }if ($license->activations()->where('status', 'active')->count() >= $license->max_activations) {
            throw ValidationException::withMessages(['license_key' => 'Maximum activations reached.']);
        }if ($activation) {
            $activation->update(['status' => 'active', 'device_name' => $data['device_name'] ?? null, 'activated_at' => now(), 'deactivated_at' => null, 'last_verified_at' => now(), 'ip_address' => $ip]);
        } else {
            $activation = $license->activations()->create(['device_fingerprint' => $fingerprint, 'device_name' => $data['device_name'] ?? null, 'status' => 'active', 'activated_at' => now(), 'last_verified_at' => now(), 'ip_address' => $ip]);
        }

        return $this->payload($license, $activation);
    }

    public function verify(ApiClient $client, array $data): array
    {
        $license = $this->resolve($client, $data['license_key']);
        $activation = $license->activations()->where('device_fingerprint', $this->fingerprint($data['device_id']))->where('status', 'active')->first();
        if (! $activation) {
            throw ValidationException::withMessages(['device_id' => 'No active license activation exists for this device.']);
        }$activation->update(['last_verified_at' => now()]);

        return $this->payload($license, $activation);
    }

    public function deactivate(ApiClient $client, array $data): array
    {
        $license = $this->resolve($client, $data['license_key']);
        $activation = $license->activations()->where('device_fingerprint', $this->fingerprint($data['device_id']))->where('status', 'active')->first();
        if (! $activation) {
            throw ValidationException::withMessages(['device_id' => 'No active license activation exists for this device.']);
        }$activation->update(['status' => 'deactivated', 'deactivated_at' => now()]);

        return ['license_uuid' => $license->uuid, 'activation_uuid' => $activation->uuid, 'status' => 'deactivated'];
    }

    private function resolve(ApiClient $client, string $key): SoftwareLicense
    {
        $license = SoftwareLicense::query()->with(['subscription', 'activations'])->where('product_id', $client->product_id)->where('key_lookup', $this->lookup($key))->first();
        if (! $license || ! Hash::check($key, $license->key_hash) || $license->status !== 'active' || ($license->expires_at && $license->expires_at->isPast()) || ! in_array($license->subscription->status->value, ['trial', 'active', 'grace'], true)) {
            throw ValidationException::withMessages(['license_key' => 'License is invalid or inactive.']);
        }

        return $license;
    }

    private function payload(SoftwareLicense $license, $activation): array
    {
        return ['license_uuid' => $license->uuid, 'activation_uuid' => $activation->uuid, 'status' => 'active', 'expires_at' => $license->expires_at?->toISOString(), 'subscription_uuid' => $license->subscription->uuid];
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

