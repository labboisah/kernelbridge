<?php

namespace Tests\Feature\Api;

use App\Domain\Integration\Services\ApiCredentialService;
use App\Domain\Licensing\Services\LicenseService;
use App\Enums\UserRole;
use App\Models\ApiClient;
use App\Models\Customer;
use App\Models\EntitlementCatalogRevision;
use App\Models\LicensePlatformAccountLink;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\DemoProfileSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlatformEntitlementUpdateApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/platform/entitlement-grants';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class, DemoProfileSeeder::class, PlanSeeder::class]);
    }

    public function test_it_accepts_scoped_updates_idempotently_without_changing_license_lifecycle(): void
    {
        $setup = $this->platformInstallation();
        $payload = $this->updatePayload($setup);
        $licenseExpiresAt = $setup['license']->expires_at?->toISOString();
        $subscriptionStatus = $setup['license']->subscription->status->value;
        $subscriptionEndsAt = $setup['license']->subscription->ends_at?->toISOString();

        $first = $this->withToken($setup['token'])->postJson(self::ENDPOINT, $payload)
            ->assertOk()
            ->assertJsonPath('data.source', 'product_platform')
            ->assertJsonPath('data.source_revision', 1)
            ->assertJsonPath('data.idempotent', false);
        self::assertSame($setup['license']->uuid, $first->json('data.license_uuid'));
        $this->assertDatabaseCount('entitlement_source_grants', 1);
        $this->assertDatabaseHas('entitlement_framework_audits', ['outcome' => 'accepted', 'source_revision' => 1]);

        $reordered = array_reverse($payload, true);
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $reordered)
            ->assertOk()
            ->assertJsonPath('data.idempotent', true);
        $this->assertDatabaseCount('entitlement_source_grants', 1);

        $conflictingRetry = $payload;
        $conflictingRetry['grants']['laboratory.results.view']['enabled'] = false;
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $conflictingRetry)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_conflict');
        $this->assertDatabaseHas('entitlement_framework_audits', ['outcome' => 'rejected', 'error_code' => 'idempotency_key_conflict']);

        $setup['license']->refresh();
        $setup['license']->subscription->refresh();
        $this->assertSame('active', $setup['license']->status);
        $this->assertSame($licenseExpiresAt, $setup['license']->expires_at?->toISOString());
        $this->assertSame($subscriptionStatus, $setup['license']->subscription->status->value);
        $this->assertSame($subscriptionEndsAt, $setup['license']->subscription->ends_at?->toISOString());
    }

    public function test_it_enforces_product_scopes_and_server_side_license_account_link_binding(): void
    {
        $setup = $this->platformInstallation();
        $credentialService = app(ApiCredentialService::class);
        $limitedClient = $credentialService->createClient($setup['product'], 'Broad licensing scope', ['scopes' => ['licensing:*']]);
        $limitedToken = $credentialService->issue($limitedClient, 'limited')['token'];

        $this->withToken($limitedToken)->postJson(self::ENDPOINT, $this->updatePayload($setup))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_scope');

        $wrongProduct = $this->updatePayload($setup);
        $wrongProduct['product_code'] = 'OTHERPRODUCT';
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $wrongProduct)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'product_mismatch');

        $wrongLink = $this->updatePayload($setup);
        $wrongLink['account_link_uuid'] = (string) Str::uuid();
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $wrongLink)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_link_mismatch');

        $wrongLicense = $this->updatePayload($setup);
        $wrongLicense['license_uuid'] = (string) Str::uuid();
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $wrongLicense)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'license_not_found');

        $this->assertDatabaseCount('entitlement_source_grants', 0);
        $this->assertDatabaseCount('entitlement_framework_audits', 3);
    }

    public function test_it_rejects_stale_unknown_invalid_and_duplicate_grants(): void
    {
        $setup = $this->platformInstallation();
        $payload = $this->updatePayload($setup);
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $payload)->assertOk();

        $stale = $this->updatePayload($setup, sourceRevision: 1, idempotencyKey: 'stale-retry');
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $stale)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'stale_source_revision');

        $unknown = $this->updatePayload($setup, sourceRevision: 2, idempotencyKey: 'unknown-grant');
        $unknown['grants'] = ['laboratory.results.missing' => ['type' => 'capability', 'enabled' => true]];
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $unknown)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'unknown_grant');

        $invalidUnit = $this->updatePayload($setup, sourceRevision: 2, idempotencyKey: 'invalid-unit');
        $invalidUnit['grants'] = ['branches.max' => [
            'type' => 'limit',
            'state' => 'finite',
            'value' => 5,
            'unit' => 'users',
            'scope' => 'business',
        ]];
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $invalidUnit)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_unit');

        $invalidSource = $this->updatePayload($setup, sourceRevision: 2, idempotencyKey: 'invalid-source');
        $invalidSource['source'] = 'direct';
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $invalidSource)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_source');

        $duplicateJson = '{"schema_version":1,"product_code":"'.$setup['product']->code
            .'","license_uuid":"'.$setup['license']->uuid
            .'","account_link_uuid":"'.$setup['link']->uuid
            .'","catalog_revision":1,"source_revision":2,"idempotency_key":"duplicate","grants":{"laboratory.results.view":{"type":"capability","enabled":true},"laboratory.results.view":{"type":"capability","enabled":false}}}';
        $this->withToken($setup['token'])->call(
            'POST',
            self::ENDPOINT,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$setup['token'],
            ],
            $duplicateJson,
        )->assertUnprocessable()->assertJsonPath('error.code', 'duplicate_key');

        $this->assertDatabaseCount('entitlement_source_grants', 1);
    }

    public function test_it_rejects_updates_for_non_platform_source_or_unapproved_links(): void
    {
        $setup = $this->platformInstallation();
        $setup['product']->forceFill(['licensing_mode' => 'direct'])->save();
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $this->updatePayload($setup))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'invalid_source');

        $setup['product']->forceFill(['licensing_mode' => 'platform_account'])->save();
        $setup['link']->forceFill(['status' => 'pending'])->save();
        $this->withToken($setup['token'])->postJson(self::ENDPOINT, $this->updatePayload($setup))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'account_link_not_approved');
        $this->assertDatabaseCount('entitlement_source_grants', 0);
    }

    /** @return array{product: Product, license: SoftwareLicense, link: LicensePlatformAccountLink, token: string} */
    private function platformInstallation(): array
    {
        $product = Product::query()->where('code', 'ALIGNEX')->firstOrFail();
        $product->forceFill(['licensing_mode' => 'platform_account'])->save();
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        $user = User::factory()->create();
        $user->assignRole(UserRole::Admin->value);
        $customer = Customer::query()->create([
            'customer_number' => 'KBT-CUS-'.strtoupper(Str::random(10)),
            'type' => 'organization',
            'name' => 'Entitlement Test Customer',
            'email' => Str::uuid().'@example.test',
            'status' => 'active',
        ]);
        $subscription = Subscription::query()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'subscription_number' => 'KBT-SUB-'.strtoupper(Str::random(10)),
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addYear(),
            'auto_renew' => true,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'billing_cycle' => $plan->billing_cycle,
            'created_by' => $user->id,
        ]);
        $license = app(LicenseService::class)->issue($subscription, 1)['license'];

        $credentialService = app(ApiCredentialService::class);
        /** @var ApiClient $client */
        $client = $credentialService->createClient($product, 'Entitlement grant integration', [
            'scopes' => ['licensing:entitlement-grants:write'],
        ]);
        $token = $credentialService->issue($client, 'entitlement-test')['token'];
        $link = LicensePlatformAccountLink::query()->create([
            'software_license_id' => $license->id,
            'product_id' => $product->id,
            'business_uuid' => (string) Str::uuid(),
            'status' => 'approved',
            'requested_by_api_client_id' => $client->id,
            'approved_at' => now(),
        ]);

        $catalog = [
            'schema_version' => 1,
            'product_code' => $product->code,
            'catalog_revision' => 1,
            'packages' => [[
                'key' => 'clinical',
                'modules' => [[
                    'key' => 'laboratory',
                    'capabilities' => [
                        ['key' => 'laboratory.results.view', 'type' => 'capability'],
                        ['key' => 'branches.max', 'type' => 'limit', 'unit' => 'branches', 'scope' => 'business'],
                    ],
                ]],
            ]],
            'plans' => [],
        ];
        EntitlementCatalogRevision::query()->create([
            'product_id' => $product->id,
            'schema_version' => 1,
            'catalog_revision' => 1,
            'catalog' => $catalog,
            'catalog_digest' => hash('sha256', json_encode($catalog, JSON_THROW_ON_ERROR)),
            'published_at' => now(),
        ]);

        return compact('product', 'license', 'link', 'token');
    }

    private function updatePayload(array $setup, int $sourceRevision = 1, string $idempotencyKey = 'grant-update-1'): array
    {
        return [
            'schema_version' => 1,
            'product_code' => $setup['product']->code,
            'license_uuid' => $setup['license']->uuid,
            'account_link_uuid' => $setup['link']->uuid,
            'catalog_revision' => 1,
            'source_revision' => $sourceRevision,
            'idempotency_key' => $idempotencyKey,
            'grants' => [
                'laboratory.results.view' => ['type' => 'capability', 'enabled' => true],
            ],
        ];
    }
}
