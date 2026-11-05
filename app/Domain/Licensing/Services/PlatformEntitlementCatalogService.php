<?php

namespace App\Domain\Licensing\Services;

use App\Models\ApiClient;
use App\Models\EntitlementCatalogRevision;
use App\Models\EntitlementFrameworkAudit;
use App\Models\Product;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use stdClass;

final class PlatformEntitlementCatalogService
{
    public function __construct(private readonly PlatformEntitlementUpdateValidator $validator) {}

    /**
     * @return array{success: true, data: array<string, mixed>}|array{success: false, code: string, message: string, status: int}
     */
    public function publish(ApiClient $client, string $payloadJson): array
    {
        return DB::transaction(function () use ($client, $payloadJson): array {
            $payloadResult = $this->validator->decode($payloadJson);
            if ($payloadResult['error'] !== null) {
                return $this->reject(
                    $client,
                    $payloadResult['error'],
                    422,
                    null,
                    hash('sha256', $payloadJson),
                );
            }
            $catalog = $payloadResult['payload'];
            if (! $catalog instanceof stdClass) {
                return $this->reject($client, 'catalog_invalid', 422, null, hash('sha256', $payloadJson));
            }

            $product = Product::query()->whereKey($client->product_id)->lockForUpdate()->first();
            if (! $product) {
                return $this->reject($client, 'product_not_found', 404, null, hash('sha256', $payloadJson));
            }
            $digest = CanonicalJson::digest($catalog);
            $revision = is_int($catalog->catalog_revision ?? null) ? $catalog->catalog_revision : null;
            $validation = $this->validator->validateCatalog($catalog, (string) $product->code);
            if (! $validation['valid']) {
                $status = $validation['error'] === 'product_mismatch' ? 403 : 422;

                return $this->reject($client, (string) $validation['error'], $status, $revision, $digest);
            }

            $latest = EntitlementCatalogRevision::query()
                ->where('product_id', $product->id)
                ->orderByDesc('catalog_revision')
                ->lockForUpdate()
                ->first();
            $currentRevision = $latest?->catalog_revision ?? 0;
            if ($revision === $currentRevision) {
                if ($latest && hash_equals($latest->catalog_digest, $digest)) {
                    $this->recordAudit($client, 'accepted', $revision, $digest);

                    return [
                        'success' => true,
                        'data' => $this->publishedResponse($latest, true),
                    ];
                }

                return $this->reject($client, 'catalog_revision_conflict', 409, $revision, $digest);
            }
            if ($revision < $currentRevision) {
                return $this->reject($client, 'stale_catalog_revision', 409, $revision, $digest);
            }

            $stored = EntitlementCatalogRevision::query()->create([
                'product_id' => $product->id,
                'schema_version' => $catalog->schema_version,
                'catalog_revision' => $revision,
                'catalog' => json_decode(json_encode($catalog, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
                'catalog_digest' => $digest,
                'published_at' => now(),
            ]);
            $this->recordAudit($client, 'accepted', $revision, $digest);

            return [
                'success' => true,
                'data' => $this->publishedResponse($stored, false),
            ];
        });
    }

    private function reject(
        ApiClient $client,
        string $code,
        int $status,
        ?int $revision,
        string $digest,
    ): array {
        $this->recordAudit($client, 'rejected', $revision, $digest, $code);

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
        ?int $revision,
        string $digest,
        ?string $errorCode = null,
    ): void {
        EntitlementFrameworkAudit::query()->create([
            'product_id' => $client->product_id,
            'source' => 'product_platform',
            'operation' => 'catalog.publish',
            'outcome' => $outcome,
            'catalog_revision' => $revision,
            'payload_digest' => $digest,
            'error_code' => $errorCode,
            'occurred_at' => now(),
        ]);
    }

    private function publishedResponse(EntitlementCatalogRevision $catalog, bool $idempotent): array
    {
        return [
            'product_code' => $catalog->product()->value('code'),
            'catalog_revision' => $catalog->catalog_revision,
            'published_at' => $catalog->published_at->toISOString(),
            'idempotent' => $idempotent,
        ];
    }

    private function message(string $code): string
    {
        return match ($code) {
            'product_mismatch' => 'The requested product does not match this API credential.',
            'stale_catalog_revision' => 'The entitlement catalog revision is stale.',
            'catalog_revision_conflict' => 'The entitlement catalog revision was already published with different content.',
            'duplicate_key' => 'The catalog contains duplicate JSON keys.',
            'malformed_json' => 'The catalog body is not valid JSON.',
            default => 'The entitlement catalog is malformed or unsupported.',
        };
    }
}
