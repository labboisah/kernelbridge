<?php

namespace App\Domain\Licensing\Services;

use App\Domain\Subscriptions\Services\PlanEntitlementService;
use App\Models\ApiClient;
use App\Models\LicenseBusinessClaim;
use App\Models\LicenseInstallationGrant;
use App\Models\SoftwareLicense;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LicenseInstallationGrantService
{
    /**
     * @param  array{license_uuid: string, business_uuid: string, branch_uuid: string, device_uuid: string, installation_uuid: string}  $data
     * @return array{grant: array<string, mixed>, credential: string, created: bool}
     */
    public function issue(ApiClient $client, array $data): array
    {
        if ($client->subscription_id) {
            throw ValidationException::withMessages([
                'credential' => 'A product-level Platform credential is required to issue installation grants.',
            ]);
        }

        return DB::transaction(function () use ($client, $data): array {
            $license = SoftwareLicense::query()
                ->with(['subscription.entitlements', 'subscription.plan.features', 'subscription.plan.limits', 'product'])
                ->where('uuid', $data['license_uuid'])
                ->where('product_id', $client->product_id)
                ->lockForUpdate()
                ->first();

            if (! $license || ! $this->licenseIsActive($license)) {
                throw ValidationException::withMessages([
                    'license_uuid' => 'The license is invalid or inactive for this product.',
                ]);
            }

            $claim = LicenseBusinessClaim::query()
                ->where('software_license_id', $license->id)
                ->where('product_id', $client->product_id)
                ->where('business_uuid', $data['business_uuid'])
                ->first();

            if (! $claim) {
                throw ValidationException::withMessages([
                    'business_uuid' => 'The license is not claimed by this business.',
                ]);
            }

            $existing = LicenseInstallationGrant::query()
                ->where('product_id', $client->product_id)
                ->where('installation_uuid', $data['installation_uuid'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! $this->hasSameBinding($existing, $license, $data)) {
                    throw ValidationException::withMessages([
                        'installation_uuid' => 'This installation is already bound to a different license, business, branch, or device.',
                    ]);
                }

                if ($existing->status === 'revoked' || $existing->revoked_at) {
                    throw ValidationException::withMessages([
                        'installation_uuid' => 'This installation grant was revoked. Register a replacement installation.',
                    ]);
                }

                if ($existing->expires_at?->isPast()) {
                    $secret = Str::random(64);
                    $existing->forceFill([
                        'credential_hash' => $this->digest($secret),
                        'credential_encrypted' => Crypt::encryptString($secret),
                        'status' => 'issued',
                        'expires_at' => $license->expires_at,
                    ])->save();

                    return [
                        'grant' => $this->publicGrant($existing),
                        'credential' => $this->token($existing, $secret),
                        'created' => false,
                    ];
                }

                return [
                    'grant' => $this->publicGrant($existing),
                    'credential' => $this->credential($existing),
                    'created' => false,
                ];
            }

            $limit = $this->installationLimit($license);
            $reserved = LicenseInstallationGrant::query()
                ->where('software_license_id', $license->id)
                ->whereIn('status', ['issued', 'active'])
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count();
            $existingActivations = $license->activations()->where('status', 'active')->count();

            if ($reserved + $existingActivations >= $limit) {
                throw ValidationException::withMessages([
                    'license_uuid' => 'The Business Server installation limit has been reached for this license.',
                ]);
            }

            $secret = Str::random(64);
            $grant = LicenseInstallationGrant::query()->create([
                'software_license_id' => $license->id,
                'product_id' => $client->product_id,
                'issued_by_api_client_id' => $client->id,
                'business_uuid' => $data['business_uuid'],
                'branch_uuid' => $data['branch_uuid'],
                'device_uuid' => $data['device_uuid'],
                'installation_uuid' => $data['installation_uuid'],
                'credential_hash' => $this->digest($secret),
                'credential_encrypted' => Crypt::encryptString($secret),
                'status' => 'issued',
                'expires_at' => $license->expires_at,
            ]);

            return [
                'grant' => $this->publicGrant($grant),
                'credential' => $this->token($grant, $secret),
                'created' => true,
            ];
        });
    }

    public function rotate(ApiClient $client, string $grantUuid): array
    {
        return DB::transaction(function () use ($client, $grantUuid): array {
            $grant = LicenseInstallationGrant::query()
                ->where('product_id', $client->product_id)
                ->where('uuid', $grantUuid)
                ->lockForUpdate()
                ->firstOrFail();

            if ($grant->status === 'revoked' || $grant->revoked_at || $grant->expires_at?->isPast()) {
                throw ValidationException::withMessages([
                    'grant' => 'A revoked or expired installation grant cannot be rotated.',
                ]);
            }

            $secret = Str::random(64);
            $grant->forceFill([
                'credential_hash' => $this->digest($secret),
                'credential_encrypted' => Crypt::encryptString($secret),
            ])->save();

            return [
                'grant' => $this->publicGrant($grant),
                'credential' => $this->token($grant, $secret),
            ];
        });
    }

    public function revoke(ApiClient $client, string $grantUuid): LicenseInstallationGrant
    {
        return DB::transaction(function () use ($client, $grantUuid): LicenseInstallationGrant {
            $initial = LicenseInstallationGrant::query()
                ->where('product_id', $client->product_id)
                ->where('uuid', $grantUuid)
                ->firstOrFail();
            $initial->license()->lockForUpdate()->firstOrFail();
            $grant = LicenseInstallationGrant::query()
                ->where('product_id', $client->product_id)
                ->where('uuid', $grantUuid)
                ->lockForUpdate()
                ->firstOrFail();

            if ($grant->status !== 'revoked') {
                $grant->forceFill([
                    'status' => 'revoked',
                    'credential_encrypted' => null,
                    'revoked_at' => now(),
                ])->save();
            }

            return $grant;
        });
    }

    /** @return array<string, mixed> */
    private function publicGrant(LicenseInstallationGrant $grant): array
    {
        return [
            'grant_uuid' => $grant->uuid,
            'license_uuid' => $grant->license()->value('uuid'),
            'product_code' => strtoupper((string) $grant->product()->value('code')),
            'business_uuid' => $grant->business_uuid,
            'branch_uuid' => $grant->branch_uuid,
            'device_uuid' => $grant->device_uuid,
            'installation_uuid' => $grant->installation_uuid,
            'status' => $grant->status,
            'expires_at' => $grant->expires_at?->toISOString(),
        ];
    }

    private function credential(LicenseInstallationGrant $grant): string
    {
        if (! $grant->credential_encrypted) {
            throw ValidationException::withMessages([
                'grant' => 'The installation credential is unavailable. Rotate the grant to issue a replacement.',
            ]);
        }

        return $this->token($grant, Crypt::decryptString($grant->credential_encrypted));
    }

    private function token(LicenseInstallationGrant $grant, string $secret): string
    {
        return $grant->uuid.'.'.$secret;
    }

    public function digest(string $secret): string
    {
        return hash_hmac('sha256', $secret, (string) config('app.key'));
    }

    private function hasSameBinding(LicenseInstallationGrant $grant, SoftwareLicense $license, array $data): bool
    {
        return $grant->software_license_id === $license->id
            && $grant->business_uuid === $data['business_uuid']
            && $grant->branch_uuid === $data['branch_uuid']
            && $grant->device_uuid === $data['device_uuid'];
    }

    public function licenseIsActive(SoftwareLicense $license): bool
    {
        $subscription = $license->subscription;
        $status = $subscription->status->value;
        $withinGrace = $status === 'grace' && $subscription->grace_ends_at?->isFuture();

        return $license->status === 'active'
            && (! $license->expires_at || $license->expires_at->isFuture())
            && (! $subscription->starts_at || $subscription->starts_at->isPast())
            && (! $subscription->ends_at || $subscription->ends_at->isFuture() || $withinGrace)
            && ($status !== 'trial' || ! $subscription->trial_ends_at || $subscription->trial_ends_at->isFuture())
            && ($status !== 'grace' || $withinGrace)
            && in_array($status, ['trial', 'active', 'grace'], true);
    }

    private function installationLimit(SoftwareLicense $license): int
    {
        $entitlement = $license->subscription->entitlements
            ->first(fn ($entitlement): bool => $entitlement->feature_key === 'max_server_installations'
                && $entitlement->effective_until === null
                && (! $entitlement->effective_from || $entitlement->effective_from->isPast()));
        $limit = $entitlement
            ? ($entitlement->enabled ? $entitlement->limit_value : null)
            : app(PlanEntitlementService::class)->limit($license->subscription->plan, 'max_server_installations');

        if (! is_numeric($limit) || (int) $limit < 1) {
            throw ValidationException::withMessages([
                'license_uuid' => 'The license has no valid max_server_installations entitlement.',
            ]);
        }

        return (int) $limit;
    }
}
