<?php

namespace App\Domain\Licensing\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Models\LicenseActivationCode;
use App\Models\LicenseInstallation;
use App\Models\LicensePlatformAccountLink;
use App\Models\Product;
use App\Models\SoftwareLicense;
use App\Models\User;
use App\Domain\Licensing\Exceptions\InstallationVerificationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InstallationActivationService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly LicenseInstallationGrantService $grants,
        private readonly InstallationSeatAllocator $seats,
        private readonly EntitlementSnapshotService $snapshots,
        private readonly EntitlementSigner $signer,
        private readonly LicenseSecretHasher $secretHasher,
    ) {}

    /**
     * @return array{activation_code: LicenseActivationCode, code: string}
     */
    public function issueCode(
        SoftwareLicense $license,
        ?User $issuedBy = null,
        ?string $replaceCodeUuid = null,
        ?string $boundInstallationUuid = null,
        ?LicensePlatformAccountLink $platformLink = null,
        ?string $apiClientUuid = null,
        ?string $deviceName = null,
    ): array
    {
        return DB::transaction(function () use ($license, $issuedBy, $replaceCodeUuid, $boundInstallationUuid, $platformLink, $apiClientUuid, $deviceName): array {
            $license = SoftwareLicense::query()
                ->with(['subscription.entitlements', 'subscription.plan.features', 'subscription.plan.limits', 'product'])
                ->lockForUpdate()
                ->findOrFail($license->id);
            $product = Product::query()->whereKey($license->product_id)->lockForUpdate()->firstOrFail();
            $license->setRelation('product', $product);
            if ($platformLink) {
                $platformLink = LicensePlatformAccountLink::query()->whereKey($platformLink->id)->lockForUpdate()->firstOrFail();
            }

            $platformIssued = $platformLink !== null
                && $platformLink->software_license_id === $license->id
                && $platformLink->product_id === $license->product_id
                && $platformLink->status === 'approved'
                && in_array($product->licensing_mode, ['platform_account', 'hybrid'], true)
                && $boundInstallationUuid !== null;
            if (! in_array($license->product->licensing_mode, ['direct', 'hybrid'], true) && ! $platformIssued) {
                throw ValidationException::withMessages([
                    'license' => 'Activation codes can only be issued through the configured licensing flow.',
                ]);
            }

            if (! $this->grants->licenseIsActive($license)) {
                throw ValidationException::withMessages(['license' => 'The license is invalid or inactive.']);
            }
            if ($license->product->licensing_mode === 'platform_account' && ! $platformIssued) {
                throw ValidationException::withMessages(['license' => 'An approved platform-account link is required to issue an installation code.']);
            }

            $replacedCode = null;
            if ($replaceCodeUuid !== null) {
                $replacedCode = LicenseActivationCode::query()
                    ->where('software_license_id', $license->id)
                    ->where('uuid', $replaceCodeUuid)
                    ->lockForUpdate()
                    ->first();

                if (! $replacedCode || $replacedCode->status !== 'issued' || $replacedCode->expires_at->isPast()) {
                    throw ValidationException::withMessages([
                        'activation_code' => 'Only an unused, unexpired activation code can be replaced.',
                    ]);
                }
            }

            $this->seats->assertAvailable($license, ['activation_code_id' => $replacedCode?->id]);

            $code = 'KBI-'.strtoupper(Str::random(8).'-'.Str::random(8).'-'.Str::random(8));
            $lookup = $this->secretHasher->hash(strtoupper(trim($code)));
            $activationCode = LicenseActivationCode::query()->create([
                'software_license_id' => $license->id,
                'product_id' => $license->product_id,
                'license_platform_account_link_id' => $platformIssued ? $platformLink->id : null,
                'bound_installation_uuid' => $boundInstallationUuid,
                'device_name' => $deviceName,
                'issued_by' => $issuedBy?->id,
                'code_lookup' => $lookup['hash'],
                'code_lookup_key_id' => $lookup['key_id'],
                'code_hash' => Hash::make($code),
                'status' => 'issued',
                'expires_at' => now()->addHours(24),
                'replacement_of_id' => $replacedCode?->id,
            ]);

            if ($replacedCode) {
                $replacedCode->forceFill([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'replaced_by_id' => $activationCode->id,
                ])->save();
            }

            $this->audit->record('license.activation_code.issued', $issuedBy, [
                'license_uuid' => $license->uuid,
                'product_uuid' => $license->product->uuid,
                'activation_code_uuid' => $activationCode->uuid,
                'expires_at' => $activationCode->expires_at->toIso8601String(),
                'replaced_code_uuid' => $replacedCode?->uuid,
                'platform_account_link_uuid' => $platformLink?->uuid,
                'bound_installation_uuid' => $boundInstallationUuid,
                'api_client_uuid' => $apiClientUuid,
            ], $activationCode);

            return ['activation_code' => $activationCode, 'code' => $code];
        });
    }

    /**
     * @param  array{activation_code: string, product_code: string, installation_uuid: string, device_name?: ?string}  $data
     * @return array{installation_uuid: string, license_uuid: string, product_code: string, credential_type: string, credential: string, expires_at: ?string, signed_entitlements: array{payload_json: string, signature: string, algorithm: string, key_id: string}, created: bool}
     */
    public function exchange(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $code = strtoupper(trim($data['activation_code']));
            $candidate = LicenseActivationCode::query()
                ->whereIn('code_lookup', $this->secretHasher->candidateHashes($code))
                ->first();

            if (! $candidate) {
                throw new InstallationVerificationException('activation_code_invalid', 'The activation code is invalid.', 422);
            }

            $license = SoftwareLicense::query()
                ->with(['subscription.entitlements', 'subscription.plan.features', 'subscription.plan.limits', 'product'])
                ->lockForUpdate()
                ->find($candidate->software_license_id);

            if (! $license) {
                throw new InstallationVerificationException('activation_code_invalid', 'The activation code is invalid.', 422);
            }

            $activationCode = LicenseActivationCode::query()
                ->with(['license.subscription.entitlements', 'license.subscription.plan.features', 'license.subscription.plan.limits', 'product'])
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->first();

            if (! $activationCode || ! Hash::check($code, $activationCode->code_hash)) {
                throw new InstallationVerificationException('activation_code_invalid', 'The activation code is invalid.', 422);
            }

            if (strtoupper($data['product_code']) !== strtoupper((string) $activationCode->product->code)) {
                throw new InstallationVerificationException('product_mismatch', 'The activation code is not valid for this product.', 403);
            }
            if ($activationCode->bound_installation_uuid && $activationCode->bound_installation_uuid !== $data['installation_uuid']) {
                throw new InstallationVerificationException('activation_code_installation_mismatch', 'This activation code was issued for a different installation.', 403);
            }
            if ($activationCode->license_platform_account_link_id && $activationCode->platformAccountLink?->status !== 'approved') {
                throw new InstallationVerificationException('platform_account_not_approved', 'The platform-account link is no longer approved.', 403);
            }

            if (! $this->signer->configured()) {
                throw new InstallationVerificationException('signing_not_configured', 'Entitlement signing is not configured.', 503);
            }

            if (! $this->grants->licenseIsActive($license)) {
                throw new InstallationVerificationException('license_inactive', 'The license is invalid or inactive.', 403);
            }

            if ($activationCode->status === 'redeemed') {
                if ($activationCode->redeemed_installation_uuid !== $data['installation_uuid']) {
                    throw new InstallationVerificationException('activation_code_used', 'This activation code has already been used.', 409);
                }

                $installation = LicenseInstallation::query()
                    ->where('product_id', $activationCode->product_id)
                    ->where('installation_uuid', $data['installation_uuid'])
                    ->lockForUpdate()
                    ->first();

                if (! $installation || $installation->software_license_id !== $activationCode->software_license_id || $installation->status !== 'active') {
                    throw new InstallationVerificationException('installation_inactive', 'The installation is no longer active.', 403);
                }

                return $this->responseFor($installation, false);
            }

            if ($activationCode->status === 'issued' && $activationCode->expires_at->isPast()) {
                throw new InstallationVerificationException('activation_code_expired', 'The activation code has expired.', 410);
            }

            if ($activationCode->status !== 'issued') {
                $errorCode = $activationCode->status === 'revoked' ? 'activation_code_revoked' : 'activation_code_invalid';
                throw new InstallationVerificationException($errorCode, 'The activation code is invalid or inactive.', 422);
            }

            $installation = LicenseInstallation::query()
                ->where('product_id', $activationCode->product_id)
                ->where('installation_uuid', $data['installation_uuid'])
                ->lockForUpdate()
                ->first();

            if ($installation && $installation->software_license_id !== $license->id) {
                throw new InstallationVerificationException('installation_bound', 'This installation is already bound to another license.', 409);
            }

            if ($installation && $installation->status !== 'active') {
                throw new InstallationVerificationException('installation_inactive', 'This installation was deactivated or revoked. Register a replacement installation.', 403);
            }

            if (! $installation) {
                try {
                    $this->seats->assertAvailable($license, ['activation_code_id' => $activationCode->id]);
                } catch (ValidationException) {
                    throw new InstallationVerificationException('seat_limit_reached', 'The shared installation limit has been reached for this license.', 409);
                }
            }

            if (! $installation) {
                $initialDigest = $this->secretHasher->hash(Str::random(64));
                $installation = LicenseInstallation::query()->create([
                    'software_license_id' => $license->id,
                    'product_id' => $license->product_id,
                    'license_platform_account_link_id' => $activationCode->license_platform_account_link_id,
                    'installation_uuid' => $data['installation_uuid'],
                    'business_uuid' => $activationCode->platformAccountLink?->business_uuid,
                    'device_name' => $data['device_name'] ?? $activationCode->device_name,
                    'credential_hash' => $initialDigest['hash'],
                    'credential_key_id' => $initialDigest['key_id'],
                    'runtime_type' => $activationCode->product->runtime_type,
                    'status' => 'active',
                    'activated_at' => now(),
                    'expires_at' => $license->expires_at,
                ]);
            }

            $result = $this->responseFor($installation, true);
            $activationCode->forceFill([
                'status' => 'redeemed',
                'redeemed_at' => now(),
                'redeemed_installation_uuid' => $installation->installation_uuid,
            ])->save();

            return $result;
        });
    }

    public function authenticate(string $token): ?LicenseInstallation
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $installation = LicenseInstallation::query()
            ->with(['license.subscription.plan', 'product'])
            ->where('uuid', $parts[0])
            ->first();

        return $installation && $this->secretHasher->matches($parts[1], $installation->credential_hash, $installation->credential_key_id)
            ? $installation
            : null;
    }

    /**
     * @return array{installation_uuid: string, status: string, deactivated_at: ?string, revoked_at: ?string}
     */
    public function deactivateWithCredential(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new InstallationVerificationException('invalid_credential', 'The installation credential is invalid.', 401);
        }

        return $this->markInactive($parts[0], $parts[1], null, 'installation_credential', 'deactivated');
    }

    /**
     * @return array{installation_uuid: string, status: string, deactivated_at: ?string, revoked_at: ?string}
     */
    public function revokeForCustomer(LicenseInstallation $installation, Authenticatable $actor): array
    {
        return $this->markInactive($installation->uuid, null, $actor, 'customer_portal', 'revoked');
    }

    /**
     * @return array{installation_uuid: string, status: string, deactivated_at: ?string, revoked_at: ?string}
     */
    private function markInactive(
        string $uuid,
        ?string $secret,
        ?Authenticatable $actor,
        string $source,
        string $status,
    ): array {
        return DB::transaction(function () use ($uuid, $secret, $actor, $source, $status): array {
            $candidate = LicenseInstallation::query()->where('uuid', $uuid)->first();
            if (! $candidate) {
                throw new InstallationVerificationException('installation_not_found', 'The installation was not found.', 404);
            }

            SoftwareLicense::query()->whereKey($candidate->software_license_id)->lockForUpdate()->firstOrFail();
            $installation = LicenseInstallation::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if ($secret !== null && ! $this->secretHasher->matches($secret, $installation->credential_hash, $installation->credential_key_id)) {
                throw new InstallationVerificationException('invalid_credential', 'The installation credential is invalid.', 401);
            }

            if ($installation->status === 'active') {
                $now = now();
                $installation->forceFill([
                    'status' => $status,
                    'deactivated_at' => $now,
                    'revoked_at' => $status === 'revoked' ? $now : null,
                    'credential_encrypted' => null,
                ])->save();

                $this->audit->record('license.installation.'.$status, $actor, [
                    'installation_uuid' => $installation->installation_uuid,
                    'license_uuid' => $installation->license()->value('uuid'),
                    'product_uuid' => $installation->product()->value('uuid'),
                    'source' => $source,
                    'seat_released' => true,
                ], $installation);
            }

            return [
                'installation_uuid' => $installation->installation_uuid,
                'status' => $installation->status,
                'deactivated_at' => $installation->deactivated_at?->toISOString(),
                'revoked_at' => $installation->revoked_at?->toISOString(),
            ];
        });
    }

    /**
     * @return array{payload_json: string, signature: string, algorithm: string, key_id: string}
     */
    public function signedEntitlements(LicenseInstallation $installation): array
    {
        $installation->loadMissing(['license.subscription.plan', 'product']);
        $license = $installation->license;
        $subscription = $this->snapshots->refreshRevision($license->subscription);
        $items = $this->snapshots->items($subscription);

        return $this->signer->sign([
            'version' => 1,
            'issued_at' => now()->toIso8601String(),
            'product_code' => strtoupper((string) $installation->product->code),
            'licensing_mode' => $installation->product->licensing_mode,
            'licensing_policy_revision' => $installation->product->licensing_policy_revision,
            'licensing_policy_effective_at' => $installation->product->licensing_mode_changed_at?->toIso8601String(),
            'installation_uuid' => $installation->installation_uuid,
            'platform_account_link_uuid' => $installation->platformAccountLink?->uuid,
            'business_uuid' => $installation->business_uuid,
            'license_uuid' => $license->uuid,
            'license_status' => $this->grants->licenseIsActive($license)
                ? (in_array($subscription->status->value, ['trial', 'grace'], true) ? $subscription->status->value : 'active')
                : ($license->status !== 'active' ? (string) $license->status : 'expired'),
            'subscription_uuid' => $subscription->uuid,
            'plan' => ['uuid' => $subscription->plan->uuid, 'name' => $subscription->plan->name],
            'expires_at' => $subscription->ends_at?->toIso8601String(),
            'grace_expires_at' => $subscription->grace_ends_at?->toIso8601String(),
            'entitlements' => $items->all(),
            'entitlement_revision' => $subscription->entitlement_revision,
            'entitlement_digest' => hash('sha256', $items->toJson()),
        ]);
    }

    /**
     * @return array{installation_uuid: string, license_uuid: string, product_code: string, credential_type: string, credential: string, expires_at: ?string, signed_entitlements: array{payload_json: string, signature: string, algorithm: string, key_id: string}, created: bool}
     */
    private function responseFor(LicenseInstallation $installation, bool $created): array
    {
        $credential = $installation->credential_encrypted
            ? Crypt::decryptString($installation->credential_encrypted)
            : $this->createCredential($installation);
        $installation->forceFill(['last_verified_at' => now()])->save();

        return [
            'installation_uuid' => $installation->installation_uuid,
            'license_uuid' => $installation->license()->value('uuid'),
            'product_code' => strtoupper((string) $installation->product()->value('code')),
            'credential_type' => 'bearer',
            'credential' => $credential,
            'expires_at' => $installation->expires_at?->toISOString(),
            'signed_entitlements' => $this->signedEntitlements($installation),
            'created' => $created,
        ];
    }

    private function createCredential(LicenseInstallation $installation): string
    {
        $secret = Str::random(64);
        $credential = $installation->uuid.'.'.$secret;
        $digest = $this->secretHasher->hash($secret);
        $installation->forceFill([
            'credential_hash' => $digest['hash'],
            'credential_key_id' => $digest['key_id'],
            'credential_encrypted' => Crypt::encryptString($credential),
        ])->save();

        return $credential;
    }
}
