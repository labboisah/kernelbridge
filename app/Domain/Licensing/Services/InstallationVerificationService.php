<?php

namespace App\Domain\Licensing\Services;

use App\Domain\Licensing\Exceptions\InstallationVerificationException;
use App\Models\LicenseInstallationGrant;

final class InstallationVerificationService
{
    public function __construct(
        private readonly EntitlementSnapshotService $snapshots,
        private readonly EntitlementSigner $signer,
        private readonly LicenseInstallationGrantService $grants,
        private readonly InstallationActivationService $activations,
    ) {}

    /**
     * @return array{payload_json: string, signature: string, algorithm: string, key_id: string}
     */
    public function verify(?string $token): array
    {
        if (! $this->signer->configured()) {
            throw new InstallationVerificationException('signing_not_configured', 'Entitlement signing is not configured.', 503);
        }

        $parts = explode('.', (string) $token, 2);
        $grant = count($parts) === 2
            ? LicenseInstallationGrant::query()->with(['license.subscription.plan', 'product'])->where('uuid', $parts[0])->first()
            : null;

        if ($grant) {
            if (! $this->grants->credentialMatches($grant, $parts[1])) {
                throw new InstallationVerificationException('invalid_credential', 'The installation credential is invalid.', 401);
            }

            if ($grant->status === 'revoked' || $grant->revoked_at) {
                throw new InstallationVerificationException('grant_revoked', 'This installation grant was revoked.', 403);
            }

            $license = $grant->license;
            $subscription = $this->snapshots->refreshRevision($license->subscription);
            $items = $this->snapshots->items($subscription);

            $grant->forceFill(['last_verified_at' => now(), 'status' => $grant->status === 'issued' ? 'active' : $grant->status])->save();

            return $this->signer->sign([
                'version' => 1,
                'issued_at' => now()->toIso8601String(),
                'product_code' => strtoupper((string) $grant->product->code),
                'licensing_mode' => $grant->product->licensing_mode,
                'licensing_policy_revision' => $grant->product->licensing_policy_revision,
                'licensing_policy_effective_at' => $grant->product->licensing_mode_changed_at?->toIso8601String(),
                'grant_uuid' => $grant->uuid,
                'license_uuid' => $license->uuid,
                'business_uuid' => $grant->business_uuid,
                'branch_uuid' => $grant->branch_uuid,
                'device_uuid' => $grant->device_uuid,
                'installation_uuid' => $grant->installation_uuid,
                'license_status' => $this->licenseStatus($license, $subscription->status->value),
                'subscription_uuid' => $subscription->uuid,
                'plan' => ['uuid' => $subscription->plan->uuid, 'name' => $subscription->plan->name],
                'expires_at' => $subscription->ends_at?->toIso8601String(),
                'grace_expires_at' => $subscription->grace_ends_at?->toIso8601String(),
                'entitlements' => $items->all(),
                'entitlement_revision' => $subscription->entitlement_revision,
                'entitlement_digest' => hash('sha256', $items->toJson()),
            ]);
        }

        $installation = $this->activations->authenticate((string) $token);
        if (! $installation) {
            throw new InstallationVerificationException('invalid_credential', 'The installation credential is invalid.', 401);
        }

        if ($installation->status === 'revoked' || $installation->revoked_at) {
            throw new InstallationVerificationException('installation_revoked', 'This installation was revoked.', 403);
        }

        if ($installation->status === 'deactivated' || $installation->deactivated_at) {
            throw new InstallationVerificationException('installation_deactivated', 'This installation was deactivated.', 403);
        }

        if ($installation->status !== 'active') {
            throw new InstallationVerificationException('installation_inactive', 'This installation is no longer active.', 403);
        }

        if ($installation->expires_at?->isPast()) {
            throw new InstallationVerificationException('installation_expired', 'This installation has expired.', 403);
        }

        $license = $installation->license;
        if (! $this->grants->licenseIsActive($license)) {
            throw new InstallationVerificationException('license_inactive', 'The license is invalid or inactive.', 403);
        }

        $installation->forceFill(['last_verified_at' => now()])->save();

        return $this->activations->signedEntitlements($installation);
    }

    private function licenseStatus($license, string $subscriptionStatus): string
    {
        if ($this->grants->licenseIsActive($license)) {
            return in_array($subscriptionStatus, ['trial', 'grace'], true) ? $subscriptionStatus : 'active';
        }

        return $license->status !== 'active' ? (string) $license->status : 'expired';
    }
}
