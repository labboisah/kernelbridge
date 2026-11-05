<?php

namespace Tests\Feature\Api;

use App\Domain\Integration\Services\ApiCredentialService;
use App\Models\ApiClient;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformEntitlementCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/platform/entitlement-catalogs';

    public function test_it_publishes_catalogs_idempotently_and_rejects_revision_conflicts(): void
    {
        $setup = $this->catalogPublisher();
        $catalog = $this->catalog($setup['product']->code);

        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $catalog)
            ->assertOk()
            ->assertJsonPath('data.catalog_revision', 1)
            ->assertJsonPath('data.idempotent', false);
        $this->assertDatabaseCount('entitlement_catalog_revisions', 1);
        $this->assertDatabaseHas('entitlement_framework_audits', [
            'operation' => 'catalog.publish',
            'outcome' => 'accepted',
            'catalog_revision' => 1,
        ]);

        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $catalog)
            ->assertOk()
            ->assertJsonPath('data.idempotent', true);

        $conflict = $catalog;
        $conflict['packages'][0]['modules'][0]['capabilities'][0]['description'] = 'Changed description';
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $conflict)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'catalog_revision_conflict');
        $this->assertDatabaseCount('entitlement_catalog_revisions', 1);
    }

    public function test_it_rejects_stale_revisions_wrong_product_and_invalid_json(): void
    {
        $setup = $this->catalogPublisher();
        $latest = $this->catalog($setup['product']->code, 2);
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $latest)->assertOk();

        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $this->catalog($setup['product']->code, 1))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'stale_catalog_revision');

        $wrongProduct = $this->catalog($setup['product']->code);
        $wrongProduct['product_code'] = 'OTHERPRODUCT';
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $wrongProduct)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'product_mismatch');

        $this->withToken($setup['token'])->call(
            'POST',
            self::ENDPOINT,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$setup['token']],
            '{"schema_version":',
        )->assertUnprocessable()->assertJsonPath('error.code', 'malformed_json');

        $duplicateJson = '{"schema_version":1,"schema_version":1}';
        $this->withToken($setup['token'])->call(
            'POST',
            self::ENDPOINT,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$setup['token']],
            $duplicateJson,
        )->assertUnprocessable()->assertJsonPath('error.code', 'duplicate_key');

        $this->assertDatabaseCount('entitlement_catalog_revisions', 1);
    }

    public function test_it_requires_the_exact_catalog_write_scope(): void
    {
        $product = Product::factory()->create();
        $client = app(ApiCredentialService::class)->createClient($product, 'Broad catalog scope', [
            'scopes' => ['licensing:*'],
        ]);
        $token = app(ApiCredentialService::class)->issue($client, 'catalog-test')['token'];

        $this->withToken($token)->postJson(self::ENDPOINT, $this->catalog($product->code))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_scope');
        $this->assertDatabaseCount('entitlement_catalog_revisions', 0);
    }

    /** @return array{product: Product, token: string} */
    private function catalogPublisher(): array
    {
        $product = Product::factory()->create();
        /** @var ApiClient $client */
        $client = app(ApiCredentialService::class)->createClient($product, 'Catalog publisher', [
            'scopes' => ['licensing:entitlement-catalog:write'],
        ]);
        $token = app(ApiCredentialService::class)->issue($client, 'catalog-test')['token'];

        return compact('product', 'token');
    }

    private function catalog(string $productCode, int $revision = 1): array
    {
        return [
            'schema_version' => 1,
            'product_code' => $productCode,
            'catalog_revision' => $revision,
            'packages' => [[
                'key' => 'core',
                'modules' => [[
                    'key' => 'records',
                    'capabilities' => [[
                        'key' => 'records.view',
                        'type' => 'capability',
                        'description' => 'View records',
                    ]],
                ]],
            ]],
            'plans' => [[
                'key' => 'standard',
                'grants' => [
                    'records.view' => ['type' => 'capability', 'enabled' => true],
                ],
            ]],
        ];
    }
}
