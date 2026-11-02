<?php

namespace App\Domain\Licensing\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Licensing\Exceptions\PlatformAccountLinkException;
use App\Models\ApiClient;
use App\Models\LicenseActivationCode;
use App\Models\LicenseInstallation;
use App\Models\LicensePlatformAccountLink;
use App\Models\Product;
use App\Models\SoftwareLicense;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PlatformAccountLinkService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly LicenseInstallationGrantService $licenses,
        private readonly InstallationActivationService $activations,
    ) {}

    /** @return array<string, mixed> */
    public function request(ApiClient $client, string $licenseUuid, string $businessUuid): array
    {
        $this->assertIntegrationScope($client);
        $businessUuid = strtolower($businessUuid);

        return DB::transaction(function () use ($client, $licenseUuid, $businessUuid): array {
            $license = SoftwareLicense::query()
                ->where('uuid', $licenseUuid)
                ->where('product_id', $client->product_id)
                ->lockForUpdate()
                ->first();

            if (! $license || ! $this->licenses->licenseIsActive($license)) {
                throw new PlatformAccountLinkException('license_inactive', 'The license is not active for this product.', 403);
            }

            $product = Product::query()->whereKey($license->product_id)->lockForUpdate()->firstOrFail();
            if (! in_array($product->licensing_mode, ['platform_account', 'hybrid'], true)) {
                throw new PlatformAccountLinkException('licensing_mode_not_supported', 'This product does not accept platform-account links.', 403);
            }

            $existingForLicense = LicensePlatformAccountLink::query()
                ->where('software_license_id', $license->id)
                ->whereIn('status', ['pending', 'approved'])
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existingForLicense) {
                if ($existingForLicense->business_uuid !== $businessUuid) {
                    throw new PlatformAccountLinkException('license_already_linked', 'This license is already linked or has a pending link to another platform account.', 409);
                }

                return $this->publicLink($existingForLicense);
            }

            $existingForBusiness = LicensePlatformAccountLink::query()
                ->where('product_id', $client->product_id)
                ->where('business_uuid', $businessUuid)
                ->whereIn('status', ['pending', 'approved'])
                ->lockForUpdate()
                ->first();

            if ($existingForBusiness && $existingForBusiness->software_license_id !== $license->id) {
                throw new PlatformAccountLinkException('platform_account_already_linked', 'This platform account is already linked or pending for another license.', 409);
            }

            $link = LicensePlatformAccountLink::query()->create([
                'software_license_id' => $license->id,
                'product_id' => $client->product_id,
                'business_uuid' => strtolower($businessUuid),
                'status' => 'pending',
                'requested_by_api_client_id' => $client->id,
            ]);

            $this->audit->record('license.platform_account_link.requested', null, [
                'link_uuid' => $link->uuid,
                'license_uuid' => $license->uuid,
                'product_uuid' => $product->uuid,
                'business_uuid' => $link->business_uuid,
                'api_client_uuid' => $client->uuid,
            ], $link);

            return $this->publicLink($link);
        });
    }

    public function status(ApiClient $client, string $linkUuid): array
    {
        $this->assertIntegrationScope($client);
        $link = LicensePlatformAccountLink::query()
            ->where('product_id', $client->product_id)
            ->where('uuid', $linkUuid)
            ->firstOrFail();

        return $this->publicLink($link);
    }

    public function approve(LicensePlatformAccountLink $link, User $actor): LicensePlatformAccountLink
    {
        return DB::transaction(function () use ($link, $actor): LicensePlatformAccountLink {
            $license = SoftwareLicense::query()->with('product')->lockForUpdate()->findOrFail($link->software_license_id);
            $product = Product::query()->whereKey($license->product_id)->lockForUpdate()->firstOrFail();
            $link = LicensePlatformAccountLink::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();

            if ($link->status === 'approved') {
                return $link;
            }
            if ($link->status !== 'pending') {
                throw new PlatformAccountLinkException('link_request_inactive', 'This account-link request is no longer pending.', 409);
            }
            if (! $this->licenses->licenseIsActive($license)) {
                throw new PlatformAccountLinkException('license_inactive', 'The license is not active.', 403);
            }
            if (! in_array($product->licensing_mode, ['platform_account', 'hybrid'], true)) {
                throw new PlatformAccountLinkException('licensing_mode_not_supported', 'This product no longer accepts platform-account links.', 403);
            }

            $licenseLink = LicensePlatformAccountLink::query()
                ->where('software_license_id', $license->id)
                ->where('status', 'approved')
                ->whereKeyNot($link->id)
                ->exists();
            $businessLink = LicensePlatformAccountLink::query()
                ->where('product_id', $license->product_id)
                ->where('business_uuid', $link->business_uuid)
                ->where('status', 'approved')
                ->whereKeyNot($link->id)
                ->exists();
            if ($licenseLink || $businessLink) {
                throw new PlatformAccountLinkException('platform_account_conflict', 'The license or platform account is already linked elsewhere.', 409);
            }

            $link->forceFill([
                'status' => 'approved',
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
                'rejected_at' => null,
            ])->save();
            $this->audit->record('license.platform_account_link.approved', $actor, [
                'link_uuid' => $link->uuid,
                'license_uuid' => $license->uuid,
                'product_uuid' => $product->uuid,
                'business_uuid' => $link->business_uuid,
            ], $link);

            return $link;
        });
    }

    public function reject(LicensePlatformAccountLink $link, User $actor): LicensePlatformAccountLink
    {
        return DB::transaction(function () use ($link, $actor): LicensePlatformAccountLink {
            $link = LicensePlatformAccountLink::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();
            if ($link->status !== 'pending') {
                throw new PlatformAccountLinkException('link_request_inactive', 'This account-link request is no longer pending.', 409);
            }

            $link->forceFill(['status' => 'rejected', 'rejected_at' => now()])->save();
            $this->audit->record('license.platform_account_link.rejected', $actor, [
                'link_uuid' => $link->uuid,
                'license_uuid' => $link->license()->value('uuid'),
                'business_uuid' => $link->business_uuid,
            ], $link);

            return $link;
        });
    }

    /** @return array{status: string, installations_revoked: int, activation_codes_revoked: int} */
    public function revoke(LicensePlatformAccountLink $link, User $actor): array
    {
        return DB::transaction(function () use ($link, $actor): array {
            $license = SoftwareLicense::query()->lockForUpdate()->findOrFail($link->software_license_id);
            $product = Product::query()->whereKey($license->product_id)->lockForUpdate()->firstOrFail();
            $link = LicensePlatformAccountLink::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();

            if ($link->status === 'revoked') {
                return ['status' => 'revoked', 'installations_revoked' => 0, 'activation_codes_revoked' => 0];
            }
            if ($link->status !== 'approved') {
                throw new PlatformAccountLinkException('link_not_active', 'Only an approved platform-account link can be revoked.', 409);
            }

            $now = now();
            $codes = LicenseActivationCode::query()
                ->where('license_platform_account_link_id', $link->id)
                ->where('status', 'issued')
                ->lockForUpdate()
                ->get();
            foreach ($codes as $code) {
                $code->forceFill(['status' => 'revoked', 'revoked_at' => $now])->save();
            }

            $installations = LicenseInstallation::query()
                ->where('license_platform_account_link_id', $link->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();
            foreach ($installations as $installation) {
                $installation->forceFill([
                    'status' => 'revoked',
                    'deactivated_at' => $now,
                    'revoked_at' => $now,
                    'credential_encrypted' => null,
                ])->save();

                $this->audit->record('license.installation.revoked', $actor, [
                    'installation_uuid' => $installation->installation_uuid,
                    'license_uuid' => $license->uuid,
                    'product_uuid' => $product->uuid,
                    'source' => 'platform_account_unlink',
                    'platform_account_link_uuid' => $link->uuid,
                    'seat_released' => true,
                ], $installation);
            }

            $seatsReleased = $installations->filter(
                fn (LicenseInstallation $installation): bool => $installation->expires_at === null || $installation->expires_at->isFuture(),
            )->count() + $codes->filter(fn (LicenseActivationCode $code): bool => $code->expires_at->isFuture())->count();

            $link->forceFill(['status' => 'revoked', 'revoked_at' => $now])->save();
            $this->audit->record('license.platform_account_link.revoked', $actor, [
                'link_uuid' => $link->uuid,
                'license_uuid' => $license->uuid,
                'product_uuid' => $product->uuid,
                'business_uuid' => $link->business_uuid,
                'installations_revoked' => $installations->count(),
                'activation_codes_revoked' => $codes->count(),
                'seats_released' => $seatsReleased,
            ], $link);

            return [
                'status' => $link->status,
                'installations_revoked' => $installations->count(),
                'activation_codes_revoked' => $codes->count(),
            ];
        });
    }

    public function issueInstallationCode(ApiClient $client, string $linkUuid, string $installationUuid, ?string $deviceName = null): array
    {
        $this->assertIntegrationScope($client);
        return DB::transaction(function () use ($client, $linkUuid, $installationUuid, $deviceName): array {
            $candidate = LicensePlatformAccountLink::query()
                ->where('product_id', $client->product_id)
                ->where('uuid', $linkUuid)
                ->first();

            if (! $candidate) {
                throw new PlatformAccountLinkException('platform_account_not_approved', 'The platform account link is not approved.', 403);
            }

            $license = SoftwareLicense::query()->lockForUpdate()->findOrFail($candidate->software_license_id);
            $product = Product::query()->whereKey($license->product_id)->lockForUpdate()->firstOrFail();
            $link = LicensePlatformAccountLink::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($link->status !== 'approved') {
                throw new PlatformAccountLinkException('platform_account_not_approved', 'The platform account link is not approved.', 403);
            }
            if (! in_array($product->licensing_mode, ['platform_account', 'hybrid'], true)) {
                throw new PlatformAccountLinkException('licensing_mode_not_supported', 'This product no longer accepts platform-account installation codes.', 403);
            }
            if (LicenseInstallation::query()
                ->where('license_platform_account_link_id', $link->id)
                ->where('installation_uuid', $installationUuid)
                ->where('status', 'active')
                ->exists()) {
                throw new PlatformAccountLinkException('installation_already_active', 'This installation is already active for the linked platform account.', 409);
            }

            $replaceCodeUuid = LicenseActivationCode::query()
                ->where('license_platform_account_link_id', $link->id)
                ->where('bound_installation_uuid', $installationUuid)
                ->where('status', 'issued')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->value('uuid');

            $issued = $this->activations->issueCode(
                $license,
                null,
                $replaceCodeUuid,
                $installationUuid,
                $link,
                $client->uuid,
                $deviceName,
            );

            return [
                'link_uuid' => $link->uuid,
                'installation_uuid' => $installationUuid,
                'activation_code' => $issued['code'],
                'expires_at' => $issued['activation_code']->expires_at->toISOString(),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function publicLink(LicensePlatformAccountLink $link): array
    {
        return [
            'uuid' => $link->uuid,
            'license_uuid' => $link->license()->value('uuid'),
            'product_code' => strtoupper((string) $link->product()->value('code')),
            'business_uuid' => $link->business_uuid,
            'status' => $link->status,
            'requested_at' => $link->created_at?->toISOString(),
            'approved_at' => $link->approved_at?->toISOString(),
            'rejected_at' => $link->rejected_at?->toISOString(),
            'revoked_at' => $link->revoked_at?->toISOString(),
        ];
    }

    private function assertIntegrationScope(ApiClient $client): void
    {
        if (! collect($client->scopes ?? [])->contains('licensing:account-link')) {
            throw new PlatformAccountLinkException('insufficient_scope', 'This API credential is not authorized for platform licensing.', 403);
        }
    }
}
