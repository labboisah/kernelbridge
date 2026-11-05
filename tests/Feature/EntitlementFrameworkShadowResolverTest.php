<?php

namespace Tests\Feature;

use App\Domain\Licensing\Services\EntitlementFrameworkShadowResolver;
use App\Models\ApiClient;
use App\Models\Customer;
use App\Models\EntitlementCatalogRevision;
use App\Models\EntitlementPlanMapping;
use App\Models\EntitlementSourceGrant;
use App\Models\LicenseInstallation;
use App\Models\LicensePlatformAccountLink;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EntitlementFrameworkShadowResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_resolution_compares_grants_without_persisting_or_changing_legacy_entitlements(): void
    {
        $setup = $this->installation('direct');
        $this->publishCatalogAndDirectMapping($setup);
        $before = $setup['subscription']->entitlements()->count();

        $result = app(EntitlementFrameworkShadowResolver::class)->resolve($setup['installation'], [
            ['key' => 'records.view', 'enabled' => false, 'limit' => null],
            ['key' => 'legacy.only', 'enabled' => true, 'limit' => null],
        ]);

        self::assertSame('direct', $result['source']);
        self::assertSame(1, $result['catalog_revision']);
        self::assertSame(3, $result['source_revision']);
        self::assertSame('resolved', $result['status']);
        self::assertTrue($result['lifecycle_eligible']);
        self::assertSame(['records.view', 'records.export'], array_keys($result['grants']));
        self::assertSame(['records.export'], $result['comparison']['added']);
        self::assertSame(['legacy.only'], $result['comparison']['removed']);
        self::assertSame(['records.view'], $result['comparison']['changed']);
        self::assertSame($before, $setup['subscription']->entitlements()->count());
        $this->assertDatabaseCount('entitlement_framework_audits', 0);
    }

    public function test_empty_direct_mapping_remains_an_object_compatible_grant_set(): void
    {
        $setup = $this->installation('direct');
        $catalog = $this->publishCatalogAndDirectMapping($setup);
        DB::table('entitlement_plan_mappings')->where('plan_id', $setup['plan']->id)->update([
            'grants' => '{}',
            'mapping_digest' => CanonicalJson::digest(new \stdClass),
        ]);

        $result = app(EntitlementFrameworkShadowResolver::class)->resolve($setup['installation'], []);

        self::assertSame('resolved', $result['status']);
        self::assertSame([], $result['grants']);
        self::assertSame($catalog->catalog_revision, $result['catalog_revision']);
    }

    public function test_hybrid_resolution_uses_only_the_activation_bound_platform_source(): void
    {
        $setup = $this->installation('hybrid', withPlatformLink: true);
        $catalog = $this->publishCatalogAndDirectMapping($setup);
        $platformGrant = EntitlementSourceGrant::query()->create([
            'product_id' => $setup['product']->id,
            'software_license_id' => $setup['license']->id,
            'license_platform_account_link_id' => $setup['link']->id,
            'entitlement_catalog_revision_id' => $catalog->id,
            'source' => 'product_platform',
            'source_revision' => 7,
            'idempotency_key' => hash_hmac('sha256', 'platform-grant-key', config('app.key')),
            'grants' => [
                'records.export' => ['type' => 'capability', 'enabled' => true],
            ],
            'payload_digest' => str_repeat('b', 64),
            'accepted_at' => now(),
        ]);

        $result = app(EntitlementFrameworkShadowResolver::class)->resolve($setup['installation'], []);

        self::assertSame('product_platform', $result['source']);
        self::assertSame($platformGrant->source_revision, $result['source_revision']);
        self::assertSame(['records.export'], array_keys($result['grants']));
        self::assertArrayNotHasKey('records.view', $result['grants']);
        self::assertSame(['records.export'], $result['comparison']['added']);
    }

    public function test_hybrid_installation_without_a_platform_binding_uses_only_its_direct_plan_mapping(): void
    {
        $setup = $this->installation('hybrid');
        $this->publishCatalogAndDirectMapping($setup);

        $result = app(EntitlementFrameworkShadowResolver::class)->resolve($setup['installation'], []);

        self::assertSame('direct', $result['source']);
        self::assertSame(['records.view', 'records.export'], array_keys($result['grants']));
    }

    public function test_hybrid_source_changes_require_a_new_authorized_activation_binding(): void
    {
        $directSetup = $this->installation('hybrid');
        $this->publishCatalogAndDirectMapping($directSetup);
        $directSetup['product']->forceFill([
            'licensing_mode' => 'platform_account',
            'licensing_mode_changed_at' => now(),
        ])->save();
        $directResult = app(EntitlementFrameworkShadowResolver::class)->resolve($directSetup['installation'], []);

        self::assertSame('direct', $directResult['source']);
        self::assertSame('source_mode_conflict', $directResult['status']);
        self::assertSame([], $directResult['grants']);

        $platformSetup = $this->installation('hybrid', withPlatformLink: true);
        $catalog = $this->publishCatalogAndDirectMapping($platformSetup);
        EntitlementSourceGrant::query()->create([
            'product_id' => $platformSetup['product']->id,
            'software_license_id' => $platformSetup['license']->id,
            'license_platform_account_link_id' => $platformSetup['link']->id,
            'entitlement_catalog_revision_id' => $catalog->id,
            'source' => 'product_platform',
            'source_revision' => 1,
            'idempotency_key' => hash_hmac('sha256', 'transition-test', config('app.key')),
            'grants' => ['records.export' => ['type' => 'capability', 'enabled' => true]],
            'payload_digest' => str_repeat('d', 64),
            'accepted_at' => now(),
        ]);
        $platformSetup['product']->forceFill([
            'licensing_mode' => 'direct',
            'licensing_mode_changed_at' => now(),
        ])->save();
        $platformResult = app(EntitlementFrameworkShadowResolver::class)->resolve($platformSetup['installation'], []);

        self::assertSame('product_platform', $platformResult['source']);
        self::assertSame('source_mode_conflict', $platformResult['status']);
        self::assertSame([], $platformResult['grants']);
    }

    public function test_ambiguous_historical_installations_do_not_guess_a_source_after_policy_change(): void
    {
        $setup = $this->installation('hybrid');
        $setup['installation']->forceFill(['entitlement_source' => null])->save();
        $setup['product']->forceFill(['licensing_mode_changed_at' => now()->addMinute()])->save();

        $result = app(EntitlementFrameworkShadowResolver::class)->resolve($setup['installation'], []);

        self::assertNull($result['source']);
        self::assertSame('unresolved_source_binding', $result['status']);
        self::assertSame([], $result['grants']);
    }

    public function test_changed_plan_uses_its_own_mapping_and_preserves_lifecycle_authority(): void
    {
        $setup = $this->installation('direct');
        $this->publishCatalogAndDirectMapping($setup);
        $downgradedPlan = Plan::factory()->create(['product_id' => $setup['product']->id]);
        $setup['subscription']->forceFill(['plan_id' => $downgradedPlan->id])->save();
        EntitlementPlanMapping::query()->create([
            'plan_id' => $downgradedPlan->id,
            'entitlement_catalog_revision_id' => EntitlementCatalogRevision::query()->where('product_id', $setup['product']->id)->value('id'),
            'source_revision' => 1,
            'grants' => ['records.view' => ['type' => 'capability', 'enabled' => true]],
            'mapping_digest' => str_repeat('c', 64),
            'published_at' => now(),
        ]);

        $setup['subscription']->forceFill(['ends_at' => now()->addYears(2), 'status' => 'active'])->save();
        $setup['license']->forceFill(['expires_at' => now()->addYears(2)])->save();
        $renewed = app(EntitlementFrameworkShadowResolver::class)->resolve($setup['installation'], []);
        self::assertTrue($renewed['lifecycle_eligible']);
        self::assertSame(['records.view'], array_keys($renewed['grants']));

        $setup['license']->forceFill(['status' => 'revoked'])->save();

        $result = app(EntitlementFrameworkShadowResolver::class)->resolve($setup['installation'], []);

        self::assertSame(['records.view'], array_keys($result['grants']));
        self::assertFalse($result['lifecycle_eligible']);
    }

    public function test_trial_grace_and_expired_subscription_states_are_reported_without_access_changes(): void
    {
        $setup = $this->installation('direct');
        $this->publishCatalogAndDirectMapping($setup);
        $resolver = app(EntitlementFrameworkShadowResolver::class);
        $cases = [
            ['trial', now()->addDay(), now()->addDay(), true],
            ['grace', now()->subDay(), now()->addDay(), true],
            ['expired', now()->subDay(), now()->subDay(), false],
        ];

        foreach ($cases as [$status, $endsAt, $trialEndsAt, $eligible]) {
            $setup['subscription']->forceFill([
                'status' => $status,
                'ends_at' => $endsAt,
                'trial_ends_at' => $status === 'trial' ? $trialEndsAt : null,
                'grace_ends_at' => $status === 'grace' ? now()->addDay() : null,
            ])->save();

            $result = $resolver->resolve($setup['installation'], []);
            self::assertSame($eligible, $result['lifecycle_eligible'], $status);
            self::assertSame(['records.view', 'records.export'], array_keys($result['grants']), $status);
        }
    }

    public function test_platform_source_fails_closed_for_an_unapproved_binding_and_expired_license(): void
    {
        $setup = $this->installation('platform_account', withPlatformLink: true);
        $setup['link']->forceFill(['status' => 'revoked'])->save();
        $setup['subscription']->forceFill(['status' => 'expired', 'ends_at' => now()->subDay()])->save();

        $result = app(EntitlementFrameworkShadowResolver::class)->resolve($setup['installation'], []);

        self::assertSame('invalid_platform_binding', $result['status']);
        self::assertSame([], $result['grants']);
        self::assertFalse($result['lifecycle_eligible']);
    }

    /** @return array{product: Product, plan: Plan, subscription: Subscription, license: SoftwareLicense, installation: LicenseInstallation, link: ?LicensePlatformAccountLink} */
    private function installation(string $licensingMode, bool $withPlatformLink = false): array
    {
        $product = Product::factory()->create(['code' => strtoupper(Str::random(8)), 'licensing_mode' => $licensingMode]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'created_by' => $user->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addYear(),
        ]);
        $license = SoftwareLicense::factory()->create([
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'active',
            'expires_at' => now()->addYear(),
        ]);

        $link = null;
        if ($withPlatformLink) {
            $client = ApiClient::query()->create([
                'product_id' => $product->id,
                'name' => 'Shadow resolver test',
                'scopes' => ['licensing:account-link'],
                'is_active' => true,
            ]);
            $link = LicensePlatformAccountLink::query()->create([
                'software_license_id' => $license->id,
                'product_id' => $product->id,
                'business_uuid' => (string) Str::uuid(),
                'status' => 'approved',
                'requested_by_api_client_id' => $client->id,
                'approved_at' => now(),
            ]);
        }

        $installation = LicenseInstallation::query()->create([
            'software_license_id' => $license->id,
            'product_id' => $product->id,
            'license_platform_account_link_id' => $link?->id,
            'entitlement_source' => $link ? 'product_platform' : 'direct',
            'installation_uuid' => (string) Str::uuid(),
            'credential_hash' => hash('sha256', Str::random(40)),
            'status' => 'active',
            'activated_at' => now(),
        ]);

        return compact('product', 'plan', 'subscription', 'license', 'installation', 'link');
    }

    private function publishCatalogAndDirectMapping(array $setup): EntitlementCatalogRevision
    {
        $catalog = EntitlementCatalogRevision::query()->create([
            'product_id' => $setup['product']->id,
            'schema_version' => 1,
            'catalog_revision' => 1,
            'catalog' => [
                'schema_version' => 1,
                'product_code' => $setup['product']->code,
                'catalog_revision' => 1,
                'packages' => [[
                    'key' => 'core',
                    'modules' => [[
                        'key' => 'records',
                        'capabilities' => [
                            ['key' => 'records.view', 'type' => 'capability'],
                            ['key' => 'records.export', 'type' => 'capability'],
                        ],
                    ]],
                ]],
                'plans' => [],
            ],
            'catalog_digest' => str_repeat('a', 64),
            'published_at' => now(),
        ]);
        $grants = [
            'records.view' => ['type' => 'capability', 'enabled' => true],
            'records.export' => ['type' => 'capability', 'enabled' => true],
        ];
        EntitlementPlanMapping::query()->create([
            'plan_id' => $setup['plan']->id,
            'entitlement_catalog_revision_id' => $catalog->id,
            'source_revision' => 3,
            'grants' => $grants,
            'mapping_digest' => CanonicalJson::digest($grants),
            'published_at' => now(),
        ]);

        return $catalog;
    }
}
