<?php

namespace App\Domain\Licensing\Services;

use App\Models\ApiClient;
use App\Models\EntitlementCatalogRevision;
use App\Models\EntitlementFrameworkAudit;
use App\Models\EntitlementSourceGrant;
use App\Models\LicensePlatformAccountLink;
use App\Models\Product;
use App\Models\SoftwareLicense;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final class PlatformEntitlementUpdateService
{
    public function __construct(
        private readonly PlatformEntitlementUpdateValidator $validator,
        private readonly LicenseInstallationGrantService $licenseGrants,
    ) {}

    /**
     * @return array{success: true, data: array<string, mixed>}|array{success: false, code: string, message: string, status: int}
     */
    public function submit(ApiClient $client, string $payloadJson): array
    {
        return DB::transaction(function () use ($client, $payloadJson): array {
            $payloadResult = $this->validator->decode($payloadJson);
            if ($payloadResult['error'] !== null) {
                return $this->reject($client, $payloadResult['error'], digest: hash('sha256', $payloadJson));
            }
            $payload = $payloadResult['payload'];
            if (! $payload instanceof stdClass) {
                return $this->reject($client, 'malformed_grant', digest: hash('sha256', $payloadJson));
            }

            $product = Product::query()->whereKey($client->product_id)->lockForUpdate()->first();
            if (! $product) {
                return $this->reject($client, 'product_not_found', 404, digest: hash('sha256', $payloadJson));
            }
            $digest = $this->digest($payload);
            $attributes = $this->auditAttributes($payload);

            if (! is_string($payload->product_code ?? null)
                || strtoupper($payload->product_code) !== strtoupper((string) $product->code)) {
                return $this->reject($client, 'product_mismatch', 403, $attributes, $digest);
            }
            if (! is_string($payload->license_uuid ?? null) || ! Str::isUuid($payload->license_uuid)) {
                return $this->reject($client, 'license_not_found', 404, $attributes, $digest);
            }

            $candidate = SoftwareLicense::query()
                ->where('product_id', $client->product_id)
                ->where('uuid', $payload->license_uuid)
                ->first();
            if (! $candidate) {
                return $this->reject($client, 'license_not_found', 404, $attributes, $digest);
            }
            $license = SoftwareLicense::query()
                ->with('subscription')
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! is_string($payload->account_link_uuid ?? null) || ! Str::isUuid($payload->account_link_uuid)) {
                return $this->reject($client, 'account_link_mismatch', 403, $attributes, $digest, $license);
            }
            $link = LicensePlatformAccountLink::query()
                ->where('product_id', $client->product_id)
                ->where('software_license_id', $license->id)
                ->where('uuid', $payload->account_link_uuid)
                ->lockForUpdate()
                ->first();
            if (! $link) {
                return $this->reject($client, 'account_link_mismatch', 403, $attributes, $digest, $license);
            }

            $idempotencyKey = $this->idempotencyDigest($payload->idempotency_key ?? null);
            if ($idempotencyKey !== null) {
                $previous = EntitlementSourceGrant::query()
                    ->where('software_license_id', $license->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($previous) {
                    if (! hash_equals($previous->payload_digest, $digest)) {
                        return $this->reject($client, 'idempotency_key_conflict', 409, $attributes, $digest, $license, $link);
                    }

                    $this->recordAudit($client, 'accepted', $attributes, $digest, $license, $link);

                    return [
                        'success' => true,
                        'data' => $this->acceptedResponse($previous, true),
                    ];
                }
            }

            if (! in_array($product->licensing_mode, ['platform_account', 'hybrid'], true)) {
                return $this->reject($client, 'invalid_source', 403, $attributes, $digest, $license, $link);
            }
            if ($link->status !== 'approved') {
                return $this->reject($client, 'account_link_not_approved', 409, $attributes, $digest, $license, $link);
            }
            if (! $this->licenseGrants->licenseIsActive($license)) {
                return $this->reject($client, 'license_inactive', 409, $attributes, $digest, $license, $link);
            }

            $catalogRevision = $payload->catalog_revision ?? null;
            if (! is_int($catalogRevision) || $catalogRevision < 1) {
                return $this->reject($client, 'catalog_revision_mismatch', 409, $attributes, $digest, $license, $link);
            }
            $catalog = EntitlementCatalogRevision::query()
                ->where('product_id', $client->product_id)
                ->where('catalog_revision', $catalogRevision)
                ->lockForUpdate()
                ->first();
            $latestCatalogRevision = EntitlementCatalogRevision::query()
                ->where('product_id', $client->product_id)
                ->max('catalog_revision');
            if (! $catalog || (int) $latestCatalogRevision !== $catalogRevision) {
                return $this->reject($client, 'catalog_revision_mismatch', 409, $attributes, $digest, $license, $link);
            }

            $definitions = $this->validator->catalogDefinitions(
                $catalog->catalog,
                (string) $product->code,
                $catalogRevision,
            );
            if ($definitions['error'] !== null) {
                return $this->reject($client, 'catalog_invalid', 409, $attributes, $digest, $license, $link);
            }

            $currentGrant = EntitlementSourceGrant::query()
                ->where('software_license_id', $license->id)
                ->where('source', 'product_platform')
                ->orderByDesc('source_revision')
                ->lockForUpdate()
                ->first();
            $currentSourceRevision = $currentGrant?->source_revision ?? 0;
            $validation = $this->validator->validate($payload, [
                'product_code' => (string) $product->code,
                'license_uuid' => $license->uuid,
                'account_link_uuid' => $link->uuid,
                'catalog_revision' => $catalogRevision,
                'current_source_revision' => $currentSourceRevision,
                'grant_definitions' => $definitions['definitions'],
            ]);
            if (! $validation['valid']) {
                $status = $validation['error'] === 'stale_source_revision' ? 409 : 422;

                return $this->reject($client, (string) $validation['error'], $status, $attributes, $digest, $license, $link);
            }

            $grants = json_decode(
                json_encode($payload->grants, JSON_THROW_ON_ERROR),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $accepted = EntitlementSourceGrant::query()->create([
                'product_id' => $client->product_id,
                'software_license_id' => $license->id,
                'license_platform_account_link_id' => $link->id,
                'entitlement_catalog_revision_id' => $catalog->id,
                'source' => 'product_platform',
                'source_revision' => $payload->source_revision,
                'idempotency_key' => $idempotencyKey,
                'grants' => $grants,
                'payload_digest' => $digest,
                'accepted_at' => now(),
            ]);
            $this->recordAudit($client, 'accepted', $attributes, $digest, $license, $link);

            return [
                'success' => true,
                'data' => $this->acceptedResponse($accepted, false),
            ];
        });
    }

    private function reject(
        ApiClient $client,
        string $code,
        int $status = 422,
        array $attributes = [],
        ?string $digest = null,
        ?SoftwareLicense $license = null,
        ?LicensePlatformAccountLink $link = null,
    ): array {
        $this->recordAudit(
            $client,
            'rejected',
            $attributes,
            $digest ?? hash('sha256', ''),
            $license,
            $link,
            $code,
        );

        return [
            'success' => false,
            'code' => $code,
            'message' => $this->message($code),
            'status' => $status,
        ];
    }

    private function recordAudit(
        ApiClient $client,
        string $outcome,
        array $attributes,
        string $digest,
        ?SoftwareLicense $license = null,
        ?LicensePlatformAccountLink $link = null,
        ?string $errorCode = null,
    ): void {
        EntitlementFrameworkAudit::query()->create([
            'product_id' => $client->product_id,
            'software_license_id' => $license?->id,
            'license_platform_account_link_id' => $link?->id,
            'source' => 'product_platform',
            'operation' => 'grant.update',
            'outcome' => $outcome,
            'catalog_revision' => $attributes['catalog_revision'] ?? null,
            'source_revision' => $attributes['source_revision'] ?? null,
            'idempotency_key' => $attributes['idempotency_key'] ?? null,
            'payload_digest' => $digest,
            'error_code' => $errorCode,
            'occurred_at' => now(),
        ]);
    }

    private function auditAttributes(stdClass $payload): array
    {
        return [
            'catalog_revision' => is_int($payload->catalog_revision ?? null) ? $payload->catalog_revision : null,
            'source_revision' => is_int($payload->source_revision ?? null) ? $payload->source_revision : null,
            'idempotency_key' => $this->idempotencyDigest($payload->idempotency_key ?? null),
        ];
    }

    private function idempotencyDigest(mixed $idempotencyKey): ?string
    {
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            return null;
        }

        return hash_hmac('sha256', $idempotencyKey, (string) config('app.key'));
    }

    private function digest(stdClass $payload): string
    {
        return CanonicalJson::digest($payload);
    }

    private function acceptedResponse(EntitlementSourceGrant $grant, bool $idempotent): array
    {
        return [
            'source' => $grant->source,
            'source_revision' => $grant->source_revision,
            'catalog_revision' => $grant->catalogRevision()->value('catalog_revision'),
            'license_uuid' => $grant->license()->value('uuid'),
            'accepted_at' => $grant->accepted_at->toISOString(),
            'idempotent' => $idempotent,
        ];
    }

    private function message(string $code): string
    {
        return match ($code) {
            'product_mismatch' => 'The requested product does not match this API credential.',
            'license_not_found' => 'The license could not be found for this product.',
            'account_link_mismatch' => 'The account link is not associated with this license and product.',
            'account_link_not_approved' => 'The platform account link is not approved.',
            'license_inactive' => 'Only a currently usable license can receive entitlement updates.',
            'invalid_source' => 'This license does not accept product-platform entitlement grants.',
            'catalog_revision_mismatch' => 'The entitlement catalog revision is not current for this product.',
            'catalog_invalid' => 'The published entitlement catalog is invalid.',
            'stale_source_revision' => 'The entitlement source revision is stale.',
            'idempotency_key_conflict' => 'The idempotency key was already used with different content.',
            'unknown_grant' => 'A grant key is not present in the published product catalog.',
            'unknown_grant_type' => 'The grant type is not supported.',
            'invalid_unit' => 'The limit unit does not match the published product catalog.',
            'invalid_scope' => 'The limit scope does not match the published product catalog.',
            'invalid_period' => 'The limit period does not match the published product catalog.',
            'duplicate_key' => 'The request contains duplicate JSON keys.',
            'malformed_json' => 'The request body is not valid JSON.',
            default => 'The entitlement update is malformed or unsupported.',
        };
    }
}
