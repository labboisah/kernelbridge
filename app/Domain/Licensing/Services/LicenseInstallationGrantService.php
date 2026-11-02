<?php

namespace App\Domain\Licensing\Services;

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
    public function __construct(
        private readonly InstallationSeatAllocator $seats,
        private readonly LicenseSecretHasher $secretHasher,
    ) {}

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
                    $digest = $this->secretHasher->hash($secret);
                    $existing->forceFill([
                        'credential_hash' => $digest['hash'],
                        'credential_key_id' => $digest['key_id'],
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

            if (in_array($license->product->licensing_mode, ['direct', 'platform_account'], true)) {
                throw ValidationException::withMessages([
                    'license_uuid' => 'This product requires its configured installation-code activation flow for new installations.',
                ]);
            }

            $this->seats->assertAvailable($license, [], 'license_uuid');

            $secret = Str::random(64);
            $digest = $this->secretHasher->hash($secret);
            $grant = LicenseInstallationGrant::query()->create([
                'software_license_id' => $license->id,
                'product_id' => $client->product_id,
                'issued_by_api_client_id' => $client->id,
                'business_uuid' => $data['business_uuid'],
                'branch_uuid' => $data['branch_uuid'],
                'device_uuid' => $data['device_uuid'],
                'installation_uuid' => $data['installation_uuid'],
                'credential_hash' => $digest['hash'],
                'credential_key_id' => $digest['key_id'],
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
            $digest = $this->secretHasher->hash($secret);
            $grant->forceFill([
                'credential_hash' => $digest['hash'],
                'credential_key_id' => $digest['key_id'],
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
        return $this->secretHasher->hash($secret)['hash'];
    }

    public function credentialMatches(LicenseInstallationGrant $grant, string $secret): bool
    {
        return $this->secretHasher->matches($secret, (string) $grant->credential_hash, $grant->credential_key_id);
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

}
