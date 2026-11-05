<?php

namespace App\Domain\Licensing\Services;

use App\Models\EntitlementCatalogRevision;
use App\Models\EntitlementPlanMapping;
use App\Models\EntitlementSourceGrant;
use App\Models\LicenseInstallation;
use App\Models\LicensePlatformAccountLink;
use App\Support\CanonicalJson;
use stdClass;

final class EntitlementFrameworkShadowResolver
{
    public function __construct(
        private readonly PlatformEntitlementUpdateValidator $validator,
        private readonly LicenseInstallationGrantService $licenseGrants,
    ) {}

    /**
     * @param  list<array{key: string, name?: string, enabled: bool, limit?: mixed}>  $legacyEntitlements
     * @return array{
     *     source: string|null,
     *     catalog_revision: int|null,
     *     source_revision: int|null,
     *     status: string,
     *     lifecycle_eligible: bool,
     *     grants: array<string, mixed>,
     *     comparison: array{added: list<string>, removed: list<string>, changed: list<string>},
     *     diagnostics: array<string, int|bool|string|null|list<string>>
     * }
     */
    public function resolve(LicenseInstallation $installation, array $legacyEntitlements): array
    {
        $installation = LicenseInstallation::query()
            ->with(['license.subscription.plan', 'product', 'platformAccountLink'])
            ->findOrFail($installation->getKey());
        $license = $installation->license;
        $subscription = $license->subscription;
        $product = $installation->product;
        $source = $this->sourceFor($installation);
        $catalog = null;
        $sourceRevision = null;
        $grants = [];
        $status = $source === null ? 'unresolved_source_binding' : 'missing_mapping';

        if ($source === 'product_platform') {
            $bindingStatus = $product->licensing_mode === 'direct'
                ? 'source_mode_conflict'
                : $this->platformBindingStatus($installation);
            if ($bindingStatus !== null) {
                $status = $bindingStatus;
            } else {
                $sourceGrant = EntitlementSourceGrant::query()
                    ->where('software_license_id', $license->id)
                    ->where('product_id', $product->id)
                    ->where('source', 'product_platform')
                    ->where('license_platform_account_link_id', $installation->license_platform_account_link_id)
                    ->orderByDesc('source_revision')
                    ->first();

                if ($sourceGrant) {
                    $sourceRevision = $sourceGrant->source_revision;
                    $catalog = EntitlementCatalogRevision::query()
                        ->whereKey($sourceGrant->entitlement_catalog_revision_id)
                        ->where('product_id', $product->id)
                        ->first();
                    $grants = $sourceGrant->grants;
                    $status = $catalog ? 'resolved' : 'invalid_catalog_binding';
                } else {
                    $status = 'missing_source_grant';
                }
            }
        } elseif ($source === 'direct') {
            if ($product->licensing_mode === 'platform_account') {
                $status = 'source_mode_conflict';
            } elseif ($subscription->product_id !== $product->id
                || $subscription->plan->product_id !== $product->id) {
                $status = 'invalid_plan_binding';
            }
            $mapping = EntitlementPlanMapping::query()
                ->where('plan_id', $subscription->plan_id)
                ->with('catalogRevision')
                ->orderByDesc('source_revision')
                ->first();

            if (! in_array($status, ['source_mode_conflict', 'invalid_plan_binding'], true) && $mapping) {
                $sourceRevision = $mapping->source_revision;
                $catalog = $mapping->catalogRevision;
                $grants = $mapping->grants;
                $status = $catalog && $catalog->product_id === $product->id
                    ? 'resolved'
                    : 'invalid_catalog_binding';
            }
        } elseif ($source !== null) {
            $status = 'unsupported_licensing_mode';
        }

        if ($status === 'resolved') {
            $validated = $this->validatedGrantSet($catalog, $grants, (string) $product->code);
            if ($validated === null) {
                $status = 'invalid_grants';
                $grants = [];
            } else {
                $grants = $validated;
            }
        }

        $comparison = $this->compare($legacyEntitlements, $grants);
        $lifecycleEligible = $this->licenseGrants->licenseIsActive($license);
        $catalogRevision = $catalog?->catalog_revision;

        return [
            'source' => $source,
            'catalog_revision' => $catalogRevision,
            'source_revision' => $sourceRevision,
            'status' => $status,
            'lifecycle_eligible' => $lifecycleEligible,
            'grants' => $grants,
            'comparison' => $comparison,
            'diagnostics' => [
                'product_code' => strtoupper((string) $product->code),
                'source' => $source,
                'status' => $status,
                'licensing_mode' => $product->licensing_mode,
                'catalog_revision' => $catalogRevision,
                'source_revision' => $sourceRevision,
                'lifecycle_eligible' => $lifecycleEligible,
                'legacy_grant_count' => count($legacyEntitlements),
                'proposed_grant_count' => count($grants),
                'added_count' => count($comparison['added']),
                'removed_count' => count($comparison['removed']),
                'changed_count' => count($comparison['changed']),
                'added_keys' => $comparison['added'],
                'removed_keys' => $comparison['removed'],
                'changed_keys' => $comparison['changed'],
                'proposed_digest' => CanonicalJson::digest($grants),
            ],
        ];
    }

    private function sourceFor(LicenseInstallation $installation): ?string
    {
        if (in_array($installation->entitlement_source, ['direct', 'product_platform'], true)) {
            return $installation->entitlement_source;
        }
        if ($installation->license_platform_account_link_id !== null) {
            return 'product_platform';
        }
        if ($installation->product->licensing_mode_changed_at
            && $installation->activated_at
            && $installation->product->licensing_mode_changed_at->gt($installation->activated_at)) {
            return null;
        }

        return in_array($installation->product->licensing_mode, ['direct', 'hybrid'], true)
            ? 'direct'
            : null;
    }

    private function platformBindingStatus(LicenseInstallation $installation): ?string
    {
        if (! $installation->license_platform_account_link_id) {
            return 'missing_platform_binding';
        }

        $link = $installation->platformAccountLink;
        if (! $link instanceof LicensePlatformAccountLink
            || $link->software_license_id !== $installation->software_license_id
            || $link->product_id !== $installation->product_id
            || $link->status !== 'approved') {
            return 'invalid_platform_binding';
        }

        return null;
    }

    private function validatedGrantSet(
        ?EntitlementCatalogRevision $catalog,
        mixed $grants,
        string $productCode,
    ): ?array {
        if (! $catalog || ! is_array($grants)) {
            return null;
        }

        $definitions = $this->validator->catalogDefinitions(
            $catalog->catalog,
            $productCode,
            $catalog->catalog_revision,
        );
        if ($definitions['error'] !== null) {
            return null;
        }

        $decoded = json_decode(json_encode((object) $grants, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        if (! $decoded instanceof stdClass
            || $this->validator->grantSetError($decoded, $definitions['definitions']) !== null) {
            return null;
        }

        return $grants;
    }

    /**
     * @param  list<array{key: string, enabled: bool, limit?: mixed}>  $legacyEntitlements
     * @param  array<string, mixed>  $grants
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}
     */
    private function compare(array $legacyEntitlements, array $grants): array
    {
        $legacy = [];
        foreach ($legacyEntitlements as $entitlement) {
            if (is_string($entitlement['key'] ?? null)) {
                $legacy[$entitlement['key']] = [
                    'enabled' => (bool) ($entitlement['enabled'] ?? false),
                    'limit' => $this->normalizeLimit($entitlement['limit'] ?? null),
                ];
            }
        }

        $proposed = [];
        foreach ($grants as $key => $grant) {
            if (! is_array($grant)) {
                continue;
            }
            $proposed[$key] = $grant['type'] === 'capability'
                ? ['enabled' => $grant['enabled'], 'limit' => null]
                : [
                    'enabled' => true,
                    'limit' => $this->normalizeLimit($grant['state'] === 'finite' ? $grant['value'] : null),
                    'unit' => $grant['unit'],
                    'scope' => $grant['scope'],
                    'period' => $grant['period'] ?? null,
                ];
        }

        $legacyKeys = array_keys($legacy);
        $proposedKeys = array_keys($proposed);
        $added = array_values(array_diff($proposedKeys, $legacyKeys));
        $removed = array_values(array_diff($legacyKeys, $proposedKeys));
        $changed = [];
        foreach (array_intersect($legacyKeys, $proposedKeys) as $key) {
            if ($legacy[$key] !== $proposed[$key]) {
                $changed[] = $key;
            }
        }
        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);
        sort($changed, SORT_STRING);

        return compact('added', 'removed', 'changed');
    }

    private function normalizeLimit(mixed $value): mixed
    {
        return is_numeric($value) ? (float) $value : $value;
    }
}
