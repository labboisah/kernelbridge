<?php

namespace Tests\Feature;

use App\Domain\Integration\Services\ApiCredentialService;
use App\Domain\Licensing\Exceptions\InstallationVerificationException;
use App\Domain\Licensing\Exceptions\PlatformAccountLinkException;
use App\Domain\Licensing\Services\InstallationActivationService;
use App\Domain\Licensing\Services\InstallationSeatAllocator;
use App\Domain\Licensing\Services\LicenseService;
use App\Domain\Licensing\Services\PlatformAccountLinkService;
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
use App\Models\SubscriptionEntitlement;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class InstallationActivationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $keyPair = sodium_crypto_sign_keypair();
        config([
            'product_integration.signing.secret_key' => base64_encode(sodium_crypto_sign_secretkey($keyPair)),
            'product_integration.signing.key_id' => 'activation-test',
        ]);
    }

    public function test_it_exchanges_a_code_and_retries_idempotently_for_the_same_installation(): void
    {
        [$product, $license] = $this->licenseFixture();
        $service = app(InstallationActivationService::class);
        $issued = $service->issueCode($license);
        $installationUuid = (string) Str::uuid();
        $request = [
            'activation_code' => strtolower($issued['code']),
            'product_code' => $product->code,
            'installation_uuid' => $installationUuid,
            'device_name' => 'Workstation 01',
        ];

        $first = $service->exchange($request);
        $this->assertTrue($first['created']);
        $this->assertSame($installationUuid, $first['installation_uuid']);
        $this->assertNotSame('', $first['credential']);
        $this->assertNotNull($service->authenticate($first['credential']));

        $product->forceFill(['licensing_mode' => 'platform_account'])->save();
        $retry = $service->exchange($request);
        $this->assertFalse($retry['created']);
        $this->assertSame($first['credential'], $retry['credential']);
        $this->assertNotNull($service->authenticate($retry['credential']));
        $this->assertSame('redeemed', $issued['activation_code']->fresh()->status);
    }

    public function test_hmac_key_rotation_keeps_activation_and_installation_credentials_valid_during_overlap(): void
    {
        [$product, $license] = $this->licenseFixture();
        $previousKey = (string) config('app.key');
        config([
            'product_integration.credential_hmac.active_key_id' => 'previous',
            'product_integration.credential_hmac.keys' => [
                'previous' => $previousKey,
                'current' => 'current-licensing-hmac-key-material',
            ],
        ]);

        $service = app(InstallationActivationService::class);
        $issued = $service->issueCode($license);
        $request = [
            'activation_code' => $issued['code'],
            'product_code' => $product->code,
            'installation_uuid' => (string) Str::uuid(),
        ];
        $activation = $service->exchange($request);
        $installation = $service->authenticate($activation['credential']);
        $this->assertNotNull($installation);
        $this->assertSame('previous', $installation->credential_key_id);

        config(['product_integration.credential_hmac.active_key_id' => 'current']);

        $retry = $service->exchange($request);
        $this->assertSame($activation['credential'], $retry['credential']);
        $this->assertNotNull($service->authenticate($activation['credential']));
    }

    public function test_it_rejects_replay_for_a_different_installation(): void
    {
        [$product, $license] = $this->licenseFixture();
        $service = app(InstallationActivationService::class);
        $issued = $service->issueCode($license);
        $data = [
            'activation_code' => $issued['code'],
            'product_code' => $product->code,
            'installation_uuid' => (string) Str::uuid(),
        ];

        $service->exchange($data);

        $this->expectException(InstallationVerificationException::class);
        $service->exchange([...$data, 'installation_uuid' => (string) Str::uuid()]);
    }

    public function test_activation_api_returns_stable_error_codes_for_replay_and_product_mismatch(): void
    {
        [$product, $license] = $this->licenseFixture();
        $issued = app(InstallationActivationService::class)->issueCode($license);
        $request = [
            'activation_code' => $issued['code'],
            'product_code' => $product->code,
            'installation_uuid' => (string) Str::uuid(),
        ];

        $this->postJson('/api/v1/installations/activate', [...$request, 'product_code' => 'WRONG'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'product_mismatch');

        $this->postJson('/api/v1/installations/activate', $request)->assertCreated();
        $this->postJson('/api/v1/installations/activate', [...$request, 'installation_uuid' => (string) Str::uuid()])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'activation_code_used');
    }

    public function test_activation_api_returns_created_then_ok_for_an_idempotent_retry(): void
    {
        [$product, $license] = $this->licenseFixture();
        $issued = app(InstallationActivationService::class)->issueCode($license);
        $request = [
            'activation_code' => $issued['code'],
            'product_code' => $product->code,
            'installation_uuid' => (string) Str::uuid(),
        ];

        $this->postJson('/api/v1/installations/activate', $request)->assertCreated()
            ->assertJsonPath('data.product_code', $product->code)
            ->assertJsonPath('data.signed_entitlements.algorithm', 'ed25519');
        $this->postJson('/api/v1/installations/activate', $request)->assertOk()
            ->assertJsonPath('data.created', false);
    }

    public function test_a_new_installation_credential_receives_a_signed_entitlement_snapshot(): void
    {
        [$product, $license] = $this->licenseFixture();
        $keyPair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keyPair);
        config([
            'product_integration.signing.secret_key' => base64_encode(sodium_crypto_sign_secretkey($keyPair)),
            'product_integration.signing.key_id' => 'activation-test',
        ]);
        $issued = app(InstallationActivationService::class)->issueCode($license);
        $activation = app(InstallationActivationService::class)->exchange([
            'activation_code' => $issued['code'],
            'product_code' => $product->code,
            'installation_uuid' => (string) Str::uuid(),
        ]);
        $this->assertSame('ed25519', $activation['signed_entitlements']['algorithm']);
        $signedPayload = json_decode($activation['signed_entitlements']['payload_json'], true);
        $this->assertSame('direct', $signedPayload['licensing_mode']);
        $this->assertSame(1, $signedPayload['licensing_policy_revision']);
        $this->assertSame(
            'direct',
            LicenseInstallation::query()
                ->where('installation_uuid', $activation['installation_uuid'])
                ->value('entitlement_source'),
        );
        $initialRevision = $signedPayload['entitlement_revision'];
        $this->assertIsInt($initialRevision);
        $signingSecret = base64_decode((string) config('product_integration.signing.secret_key'));
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            base64_decode($activation['signed_entitlements']['signature']),
            $activation['signed_entitlements']['payload_json'],
            sodium_crypto_sign_publickey_from_secretkey($signingSecret),
        ));

        $response = $this->withToken($activation['credential'])
            ->postJson('/api/v1/installations/verify')
            ->assertOk()
            ->assertJsonPath('data.key_id', 'activation-test')
            ->json('data');
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            base64_decode($response['signature']),
            $response['payload_json'],
            $publicKey,
        ));
        $this->assertSame($product->code, json_decode($response['payload_json'], true)['product_code']);

        $subscription = $license->subscription;
        $subscription->entitlements()->firstOrFail()->update(['limit_value' => '3']);
        $service = app(InstallationActivationService::class);
        $installation = $service->authenticate($activation['credential']);
        $this->assertNotNull($installation);
        $updatedSnapshot = $service->signedEntitlements($installation);
        $updatedPayload = json_decode($updatedSnapshot['payload_json'], true);
        $this->assertGreaterThan($initialRevision, $updatedPayload['entitlement_revision']);
    }

    public function test_shadow_comparison_logs_diagnostics_without_changing_the_signed_payload(): void
    {
        [$product, $license] = $this->licenseFixture();
        config(['entitlement_framework.shadow_enabled' => true]);
        Log::spy();

        $issued = app(InstallationActivationService::class)->issueCode($license);
        $activation = app(InstallationActivationService::class)->exchange([
            'activation_code' => $issued['code'],
            'product_code' => $product->code,
            'installation_uuid' => (string) Str::uuid(),
        ]);
        $payload = json_decode($activation['signed_entitlements']['payload_json'], true);

        $this->assertArrayHasKey('entitlements', $payload);
        $this->assertArrayNotHasKey('entitlement_framework', $payload);
        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'entitlement_framework.shadow_comparison'
                && isset($context['source'], $context['status'], $context['proposed_digest'])
                && isset($context['added_keys'], $context['removed_keys'], $context['changed_keys'])
                && ! array_key_exists('grants', $context)
                && ! array_key_exists('installation_uuid', $context);
        });
    }

    public function test_opt_in_signed_snapshot_includes_source_bound_framework_without_changing_legacy_entitlements(): void
    {
        [$product, $license] = $this->licenseFixture();
        $catalog = EntitlementCatalogRevision::query()->create([
            'product_id' => $product->id,
            'schema_version' => 1,
            'catalog_revision' => 1,
            'catalog' => [
                'schema_version' => 1,
                'product_code' => $product->code,
                'catalog_revision' => 1,
                'packages' => [[
                    'key' => 'core',
                    'modules' => [[
                        'key' => 'finance',
                        'capabilities' => [
                            ['key' => 'finance.view', 'type' => 'capability'],
                            ['key' => 'branches.max', 'type' => 'limit', 'unit' => 'branches', 'scope' => 'business'],
                        ],
                    ]],
                ]],
                'plans' => [],
            ],
            'catalog_digest' => str_repeat('a', 64),
            'published_at' => now(),
        ]);
        $grants = [
            'finance.view' => ['type' => 'capability', 'enabled' => true],
            'branches.max' => [
                'type' => 'limit',
                'state' => 'finite',
                'value' => 5,
                'unit' => 'branches',
                'scope' => 'business',
            ],
        ];
        EntitlementPlanMapping::query()->create([
            'plan_id' => $license->subscription->plan_id,
            'entitlement_catalog_revision_id' => $catalog->id,
            'source_revision' => 2,
            'grants' => $grants,
            'mapping_digest' => CanonicalJson::digest($grants),
            'published_at' => now(),
        ]);
        $issued = app(InstallationActivationService::class)->issueCode($license);
        $activation = app(InstallationActivationService::class)->exchange([
            'activation_code' => $issued['code'],
            'product_code' => $product->code,
            'installation_uuid' => (string) Str::uuid(),
        ]);
        $legacyPayload = json_decode($activation['signed_entitlements']['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('entitlement_framework', $legacyPayload);
        config(['entitlement_framework.snapshot_enabled' => true]);
        $installation = app(InstallationActivationService::class)->authenticate($activation['credential']);
        $this->assertNotNull($installation);
        $activation['signed_entitlements'] = app(InstallationActivationService::class)->signedEntitlements($installation);
        $signed = $activation['signed_entitlements'];
        $payload = json_decode($signed['payload_json'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('direct', $payload['entitlement_framework']['source']);
        $this->assertSame(1, $payload['entitlement_framework']['catalog_revision']);
        $this->assertSame(2, $payload['entitlement_framework']['source_revision']);
        $this->assertSame($grants, $payload['entitlement_framework']['grants']);
        $this->assertArrayHasKey('entitlements', $payload);
        $this->assertSame('ed25519', $signed['algorithm']);
        $secret = base64_decode((string) config('product_integration.signing.secret_key'), true);
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            base64_decode($signed['signature'], true),
            $signed['payload_json'],
            sodium_crypto_sign_publickey_from_secretkey($secret),
        ));

        DB::table('entitlement_plan_mappings')
            ->where('plan_id', $license->subscription->plan_id)
            ->update([
                'grants' => '{}',
                'mapping_digest' => CanonicalJson::digest(new \stdClass),
            ]);
        $emptyGrantSnapshot = app(InstallationActivationService::class)->signedEntitlements($installation);
        $emptyGrantPayload = json_decode($emptyGrantSnapshot['payload_json'], false, 512, JSON_THROW_ON_ERROR);

        $this->assertInstanceOf(\stdClass::class, $emptyGrantPayload->entitlement_framework->grants);
        $this->assertSame([], get_object_vars($emptyGrantPayload->entitlement_framework->grants));
    }

    public function test_opt_in_signed_snapshot_omits_the_extension_and_logs_when_no_source_mapping_exists(): void
    {
        [$product, $license] = $this->licenseFixture();
        config(['entitlement_framework.snapshot_enabled' => true]);
        Log::spy();

        $issued = app(InstallationActivationService::class)->issueCode($license);
        $activation = app(InstallationActivationService::class)->exchange([
            'activation_code' => $issued['code'],
            'product_code' => $product->code,
            'installation_uuid' => (string) Str::uuid(),
        ]);
        $payload = json_decode($activation['signed_entitlements']['payload_json'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('entitlement_framework', $payload);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return $message === 'entitlement_framework.snapshot_not_attached'
                && ($context['status'] ?? null) === 'missing_mapping'
                && ! array_key_exists('grants', $context);
        });
    }

    public function test_opt_in_signed_snapshot_uses_the_bound_platform_account_grant(): void
    {
        [$product, $license] = $this->licenseFixture();
        $product->forceFill(['licensing_mode' => 'platform_account'])->save();
        $client = ApiClient::query()->create([
            'product_id' => $product->id,
            'name' => 'Platform snapshot test',
            'is_active' => true,
            'rate_limit_per_minute' => 120,
            'scopes' => ['licensing:entitlement-grants:write'],
        ]);
        $businessUuid = (string) Str::uuid();
        $link = LicensePlatformAccountLink::query()->create([
            'software_license_id' => $license->id,
            'product_id' => $product->id,
            'business_uuid' => $businessUuid,
            'status' => 'approved',
            'requested_by_api_client_id' => $client->id,
            'approved_at' => now(),
        ]);
        $installationUuid = (string) Str::uuid();
        $installation = LicenseInstallation::query()->create([
            'software_license_id' => $license->id,
            'product_id' => $product->id,
            'license_platform_account_link_id' => $link->id,
            'entitlement_source' => 'product_platform',
            'installation_uuid' => $installationUuid,
            'business_uuid' => $businessUuid,
            'credential_hash' => hash('sha256', Str::random(40)),
            'status' => 'active',
            'activated_at' => now(),
        ]);
        $grants = [
            'finance.view' => ['type' => 'capability', 'enabled' => true],
        ];
        $catalog = EntitlementCatalogRevision::query()->create([
            'product_id' => $product->id,
            'schema_version' => 1,
            'catalog_revision' => 1,
            'catalog' => [
                'schema_version' => 1,
                'product_code' => $product->code,
                'catalog_revision' => 1,
                'packages' => [[
                    'key' => 'finance',
                    'modules' => [[
                        'key' => 'finance',
                        'capabilities' => [
                            ['key' => 'finance.view', 'type' => 'capability'],
                        ],
                    ]],
                ]],
                'plans' => [],
            ],
            'catalog_digest' => str_repeat('a', 64),
            'published_at' => now(),
        ]);
        EntitlementSourceGrant::query()->create([
            'product_id' => $product->id,
            'software_license_id' => $license->id,
            'license_platform_account_link_id' => $link->id,
            'entitlement_catalog_revision_id' => $catalog->id,
            'source' => 'product_platform',
            'source_revision' => 4,
            'idempotency_key' => hash_hmac('sha256', 'platform-snapshot-test', (string) config('app.key')),
            'grants' => $grants,
            'payload_digest' => str_repeat('b', 64),
            'accepted_at' => now(),
        ]);
        config(['entitlement_framework.snapshot_enabled' => true]);

        $signed = app(InstallationActivationService::class)->signedEntitlements($installation);
        $payload = json_decode($signed['payload_json'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('product_platform', $payload['entitlement_framework']['source']);
        $this->assertSame(1, $payload['entitlement_framework']['catalog_revision']);
        $this->assertSame(4, $payload['entitlement_framework']['source_revision']);
        $this->assertSame($grants, $payload['entitlement_framework']['grants']);
        $this->assertSame($link->uuid, $payload['platform_account_link_uuid']);
        $this->assertArrayHasKey('entitlements', $payload);
    }

    public function test_effective_entitlement_boundaries_advance_the_signed_revision(): void
    {
        [$product, $license] = $this->licenseFixture();
        $scheduledAt = now()->addMinute();
        $license->subscription->entitlements()->create([
            'feature_key' => 'scheduled_feature',
            'feature_name' => 'Scheduled feature',
            'enabled' => true,
            'limit_value' => null,
            'source_plan_id' => $license->subscription->plan_id,
            'effective_from' => $scheduledAt,
        ]);
        $issued = app(InstallationActivationService::class)->issueCode($license);
        $activation = app(InstallationActivationService::class)->exchange([
            'activation_code' => $issued['code'],
            'product_code' => $product->code,
            'installation_uuid' => (string) Str::uuid(),
        ]);
        $before = json_decode($activation['signed_entitlements']['payload_json'], true);
        $this->assertNotContains('scheduled_feature', array_column($before['entitlements'], 'key'));

        Carbon::setTestNow($scheduledAt->copy()->addMinute());
        try {
            $after = $this->withToken($activation['credential'])->postJson('/api/v1/installations/verify')
                ->assertOk()->json('data');
        } finally {
            Carbon::setTestNow();
        }

        $afterPayload = json_decode($after['payload_json'], true);
        $this->assertContains('scheduled_feature', array_column($afterPayload['entitlements'], 'key'));
        $this->assertGreaterThan($before['entitlement_revision'], $afterPayload['entitlement_revision']);
    }

    public function test_platform_backend_request_links_immediately_before_bound_installation_code_issuance(): void
    {
        [$product, $license] = $this->licenseFixture();
        $product->forceFill(['licensing_mode' => 'platform_account'])->save();
        $license->subscription->entitlements()->create([
            'feature_key' => 'adaptive_exam',
            'feature_name' => 'Adaptive exams',
            'enabled' => true,
            'source_plan_id' => $license->subscription->plan_id,
            'effective_from' => now()->subDay(),
        ]);
        $client = ApiClient::query()->create([
            'product_id' => $product->id,
            'name' => 'Trusted platform backend',
            'is_active' => true,
            'rate_limit_per_minute' => 120,
            'scopes' => ['licensing:account-link'],
        ]);
        $issuedCredential = app(ApiCredentialService::class)->issue($client, 'platform-backend');
        $businessUuid = (string) Str::uuid();
        $activationCode = app(LicenseService::class)->key($license);
        $this->assertNotNull($activationCode);
        $link = $this->withToken($issuedCredential['token'])->postJson('/api/v1/platform/account-links/requests', [
            'activation_code' => $activationCode,
            'business_uuid' => $businessUuid,
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.license_uuid', $license->uuid)
            ->assertJsonPath('data.subscription_uuid', $license->subscription->uuid)
            ->assertJsonPath('data.license_status', 'active')
            ->assertJsonMissing(['activation_code' => $activationCode])
            ->json('data');
        $this->assertSame($license->subscription->ends_at->toISOString(), $link['ends_at']);
        $this->assertTrue(collect($link['entitlements'])->contains(fn ($item) => $item['key'] === 'adaptive_exam' && $item['enabled'] === true));
        $this->assertDatabaseMissing('license_business_claims', [
            'software_license_id' => $license->id,
            'business_uuid' => strtolower($businessUuid),
        ]);

        $linkRecord = LicensePlatformAccountLink::query()->where('uuid', $link['uuid'])->firstOrFail();
        $this->assertNotNull($linkRecord->approved_at);
        $this->assertNull($linkRecord->approved_by_user_id);

        $linkRecord->forceFill(['status' => 'pending', 'approved_at' => null])->save();
        $retriedLink = $this->withToken($issuedCredential['token'])
            ->postJson('/api/v1/platform/account-links/requests', [
                'license_uuid' => $license->uuid,
                'business_uuid' => $businessUuid,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->json('data');
        $this->assertSame($link['uuid'], $retriedLink['uuid']);
        $this->assertSame(1, LicensePlatformAccountLink::query()
            ->where('software_license_id', $license->id)
            ->where('business_uuid', strtolower($businessUuid))
            ->count());

        $installationUuid = (string) Str::uuid();
        $issueRequest = [
            'installation_uuid' => $installationUuid,
            'device_name' => 'Clinic server',
        ];

        $codeEndpoint = '/api/v1/platform/account-links/'.$link['uuid'].'/activation-codes';
        $firstCode = $this->withToken($issuedCredential['token'])->postJson($codeEndpoint, $issueRequest)
            ->assertCreated()
            ->assertJsonPath('data.installation_uuid', $installationUuid)
            ->json('data.activation_code');
        $code = $this->withToken($issuedCredential['token'])->postJson($codeEndpoint, $issueRequest)
            ->assertCreated()
            ->json('data.activation_code');
        $this->assertNotSame($firstCode, $code);
        $this->assertSame(1, app(InstallationSeatAllocator::class)->used($license));

        $this->postJson('/api/v1/installations/activate', [
            'activation_code' => $code,
            'product_code' => $product->code,
            'installation_uuid' => (string) Str::uuid(),
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'activation_code_installation_mismatch');

        $activated = $this->postJson('/api/v1/installations/activate', [
            'activation_code' => $code,
            'product_code' => $product->code,
            'installation_uuid' => $installationUuid,
        ])->assertCreated()->json('data');

        $signedPayload = json_decode($activated['signed_entitlements']['payload_json'], true);
        $this->assertSame(strtolower($businessUuid), $signedPayload['business_uuid']);
        $this->assertTrue(collect($signedPayload['entitlements'])->contains(fn ($item) => $item['key'] === 'adaptive_exam' && $item['enabled'] === true));
        $this->assertSame($license->subscription->ends_at->toIso8601String(), $signedPayload['expires_at']);
        $linkId = LicensePlatformAccountLink::query()->where('uuid', $link['uuid'])->value('id');
        $this->assertDatabaseHas('license_installations', [
            'installation_uuid' => $installationUuid,
            'business_uuid' => strtolower($businessUuid),
            'license_platform_account_link_id' => $linkId,
            'entitlement_source' => 'product_platform',
        ]);

        $this->withToken($issuedCredential['token'])
            ->deleteJson('/api/v1/platform/account-links/'.$link['uuid'])
            ->assertOk()
            ->assertJsonPath('data.uuid', $link['uuid'])
            ->assertJsonPath('data.license_uuid', $license->uuid)
            ->assertJsonPath('data.business_uuid', strtolower($businessUuid))
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.installations_revoked', 1);

        $this->assertSame('revoked', $linkRecord->fresh()->status);
        $this->assertSame('revoked', LicenseInstallation::query()->where('installation_uuid', $installationUuid)->value('status'));
        $this->assertSame(0, app(InstallationSeatAllocator::class)->used($license));

        $replacement = $this->withToken($issuedCredential['token'])
            ->postJson('/api/v1/platform/account-links/requests', [
                'license_uuid' => $license->uuid,
                'business_uuid' => $businessUuid,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->json('data');
        $this->assertNotSame($link['uuid'], $replacement['uuid']);
    }

    public function test_installation_can_deactivate_with_its_credential_and_release_its_seat_idempotently(): void
    {
        [$product, $license] = $this->licenseFixture(1);
        $service = app(InstallationActivationService::class);
        $issued = $service->issueCode($license);
        $activation = $service->exchange([
            'activation_code' => $issued['code'],
            'product_code' => $product->code,
            'installation_uuid' => (string) Str::uuid(),
        ]);

        $this->assertSame(1, app(InstallationSeatAllocator::class)->used($license));

        $this->withToken($activation['credential'])->postJson('/api/v1/installations/deactivate')
            ->assertOk()
            ->assertJsonPath('data.status', 'deactivated')
            ->assertJsonPath('data.installation_uuid', $activation['installation_uuid']);

        $this->withToken($activation['credential'])->postJson('/api/v1/installations/deactivate')
            ->assertOk()
            ->assertJsonPath('data.status', 'deactivated');

        $this->assertSame(0, app(InstallationSeatAllocator::class)->used($license));
        $this->withToken($activation['credential'])->postJson('/api/v1/installations/verify')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'installation_deactivated');
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'license.installation.deactivated',
            'entity_type' => LicenseInstallation::class,
        ]);
        $this->assertNotNull($service->authenticate($activation['credential']));
    }

    public function test_platform_account_credential_can_revoke_one_installation_without_unlinking_the_business(): void
    {
        [$product, $license] = $this->licenseFixture(3);
        $product->forceFill(['licensing_mode' => 'platform_account'])->save();
        $client = ApiClient::query()->create([
            'product_id' => $product->id,
            'name' => 'Trusted platform backend',
            'is_active' => true,
            'rate_limit_per_minute' => 120,
            'scopes' => ['licensing:account-link'],
        ]);
        $issuedCredential = app(ApiCredentialService::class)->issue($client, 'platform-backend');
        $businessUuid = (string) Str::uuid();
        $link = $this->withToken($issuedCredential['token'])->postJson('/api/v1/platform/account-links/requests', [
            'activation_code' => app(LicenseService::class)->key($license),
            'business_uuid' => $businessUuid,
        ])->assertOk()->json('data');
        $installationUuid = (string) Str::uuid();
        $code = $this->withToken($issuedCredential['token'])
            ->postJson('/api/v1/platform/account-links/'.$link['uuid'].'/activation-codes', [
                'installation_uuid' => $installationUuid,
                'device_name' => 'MediFlow server',
            ])->assertCreated()->json('data.activation_code');
        $activation = $this->postJson('/api/v1/installations/activate', [
            'activation_code' => $code,
            'product_code' => $product->code,
            'installation_uuid' => $installationUuid,
        ])->assertCreated()->json('data');

        $this->withToken($issuedCredential['token'])
            ->postJson('/api/v1/platform/account-links/'.$link['uuid'].'/installations/'.$installationUuid.'/revoke')
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.installations_revoked', 1)
            ->assertJsonPath('data.activation_codes_revoked', 0);

        $this->withToken($activation['credential'])->postJson('/api/v1/installations/verify')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'installation_revoked');
        $this->withToken($issuedCredential['token'])
            ->getJson('/api/v1/platform/account-links/'.$link['uuid'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->assertSame(0, app(InstallationSeatAllocator::class)->used($license));

        $pendingInstallationUuid = (string) Str::uuid();
        $pendingCode = $this->withToken($issuedCredential['token'])
            ->postJson('/api/v1/platform/account-links/'.$link['uuid'].'/activation-codes', [
                'installation_uuid' => $pendingInstallationUuid,
                'device_name' => 'Pending server',
            ])->assertCreated()
            ->json('data.activation_code');
        $this->withToken($issuedCredential['token'])
            ->postJson('/api/v1/platform/account-links/'.$link['uuid'].'/installations/'.$pendingInstallationUuid.'/revoke')
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked')
            ->assertJsonPath('data.installations_revoked', 0)
            ->assertJsonPath('data.activation_codes_revoked', 1);
        $this->assertDatabaseHas('license_activation_codes', [
            'license_platform_account_link_id' => LicensePlatformAccountLink::query()->where('uuid', $link['uuid'])->value('id'),
            'bound_installation_uuid' => $pendingInstallationUuid,
            'status' => 'revoked',
        ]);
        $this->postJson('/api/v1/installations/activate', [
            'activation_code' => $pendingCode,
            'product_code' => $product->code,
            'installation_uuid' => $pendingInstallationUuid,
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'activation_code_revoked');

        $this->withToken($issuedCredential['token'])
            ->postJson('/api/v1/platform/account-links/'.$link['uuid'].'/installations/'.Str::uuid().'/revoke')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'installation_not_found');
    }

    public function test_unbound_platform_enrollment_code_binds_first_server_and_preserves_account_entitlements(): void
    {
        [$product, $license] = $this->licenseFixture(1);
        $product->forceFill(['licensing_mode' => 'platform_account'])->save();
        $client = ApiClient::query()->create([
            'product_id' => $product->id,
            'name' => 'Trusted platform backend',
            'is_active' => true,
            'rate_limit_per_minute' => 120,
            'scopes' => ['licensing:account-link'],
        ]);
        $links = app(PlatformAccountLinkService::class);
        $businessUuid = (string) Str::uuid();
        $link = $links->request($client, $license->uuid, $businessUuid);
        $credential = app(ApiCredentialService::class)->issue($client, 'enrollment-test');
        $endpoint = '/api/v1/platform/account-links/'.$link['uuid'].'/server-enrollment-codes';
        $this->postJson($endpoint)->assertUnauthorized();
        $issued = $this->withToken($credential['token'])->postJson($endpoint)
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertSame(1, app(InstallationSeatAllocator::class)->used($license));
        $this->assertDatabaseHas('license_activation_codes', [
            'uuid' => $issued['activation_code_uuid'],
            'bound_installation_uuid' => null,
            'status' => 'issued',
            'entitlement_source' => 'product_platform',
        ]);
        $this->assertEqualsWithDelta(86400, now()->diffInSeconds(Carbon::parse($issued['expires_at'])), 5);

        $serverUuid = (string) Str::uuid();
        $payload = ['activation_code' => $issued['activation_code'], 'product_code' => $product->code, 'installation_uuid' => $serverUuid];
        $result = $this->postJson('/api/v1/installations/activate', $payload)->assertCreated()->json('data');
        $claims = json_decode($result['signed_entitlements']['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(strtolower($businessUuid), $claims['business_uuid']);
        $this->assertSame($serverUuid, $claims['installation_uuid']);
        $challenge = hash('sha256', 'synthetic sync enrollment secret');
        $this->withToken($result['credential'])
            ->postJson('/api/v1/installations/operational-sync-proof', ['challenge' => $challenge])->assertNotFound();
        config(['product_integration.operational_sync_proof_enabled' => true]);
        $proof = $this->withToken($result['credential'])
            ->postJson('/api/v1/installations/operational-sync-proof', ['challenge' => $challenge])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $proofClaims = json_decode($proof['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        $key = base64_decode(config('product_integration.signing.secret_key'), true);
        $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($proof['signature'], true), $proof['payload_json'], sodium_crypto_sign_publickey_from_secretkey($key)));
        $this->assertSame('mediflow.operational-sync.enrollment', $proofClaims['purpose']);
        $this->assertSame($challenge, $proofClaims['challenge']);
        $this->assertSame($serverUuid, $proofClaims['installation_uuid']);
        $this->assertSame($link['uuid'], $proofClaims['platform_account_link_uuid']);
        $this->assertSame(strtolower($businessUuid), $proofClaims['business_uuid']);
        $this->withToken('invalid-credential')->postJson('/api/v1/installations/operational-sync-proof', ['challenge' => $challenge])->assertUnauthorized();
        $this->withToken($result['credential'])->postJson('/api/v1/installations/operational-sync-proof', ['challenge' => 'invalid'])->assertUnprocessable();
        $this->assertSame($license->subscription->ends_at->toIso8601String(), $claims['expires_at']);
        $this->assertSame(1, app(InstallationSeatAllocator::class)->used($license));
        $inventoryUrl = '/api/v1/platform/account-links/'.$link['uuid'].'/installations';
        $this->withToken('invalid-credential')->getJson($inventoryUrl)->assertUnauthorized();
        $inventory = $this->withToken($credential['token'])->getJson($inventoryUrl)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.link_uuid', $link['uuid'])
            ->assertJsonPath('data.business_uuid', strtolower($businessUuid))
            ->assertJsonPath('data.installations.0.installation_uuid', $serverUuid)
            ->assertJsonPath('data.installations.0.status', 'active')
            ->assertJsonPath('data.total', 1)->json('data');
        $this->assertArrayNotHasKey('credential_hash', $inventory['installations'][0]);
        $this->assertArrayNotHasKey('credential_encrypted', $inventory['installations'][0]);
        $otherProduct = Product::factory()->create();
        $otherClient = ApiClient::query()->create([
            'product_id' => $otherProduct->id, 'name' => 'Other product', 'is_active' => true,
            'rate_limit_per_minute' => 120, 'scopes' => ['licensing:account-link'],
        ]);
        $otherToken = app(ApiCredentialService::class)->issue($otherClient, 'other-product')['token'];
        $this->withToken($otherToken)->getJson($inventoryUrl)->assertNotFound();
        $noScope = ApiClient::query()->create([
            'product_id' => $product->id, 'name' => 'Unscoped client', 'is_active' => true,
            'rate_limit_per_minute' => 120, 'scopes' => ['mcp:read'],
        ]);
        $noScopeToken = app(ApiCredentialService::class)->issue($noScope, 'unscoped')['token'];
        $this->withToken($noScopeToken)->getJson($inventoryUrl)->assertForbidden();
        $this->withToken($credential['token'])->getJson($inventoryUrl.'?page=2')
            ->assertOk()->assertJsonPath('data.current_page', 2)->assertJsonCount(0, 'data.installations');
        $this->withToken($credential['token'])->getJson($inventoryUrl.'?page=0')->assertUnprocessable();
        $this->withToken($credential['token'])->getJson('/api/v1/platform/account-links/'.Str::uuid().'/installations')->assertNotFound();
        $this->postJson('/api/v1/installations/activate', array_merge($payload, ['installation_uuid' => (string) Str::uuid()]))
            ->assertStatus(409)->assertJsonPath('error.code', 'activation_code_used');
        $links->unlink($client, $link['uuid']);
        $this->withToken($result['credential'])->postJson('/api/v1/installations/operational-sync-proof', ['challenge' => $challenge])->assertForbidden();
        $this->withToken($credential['token'])->getJson($inventoryUrl)->assertForbidden();
        $this->withToken($result['credential'])->postJson('/api/v1/installations/verify')->assertForbidden();
    }

    public function test_unbound_enrollment_requires_scope_and_an_approved_product_link(): void
    {
        [$product, $license] = $this->licenseFixture(1);
        $product->forceFill(['licensing_mode' => 'platform_account'])->save();
        $client = ApiClient::query()->create([
            'product_id' => $product->id,
            'name' => 'Platform backend',
            'is_active' => true,
            'rate_limit_per_minute' => 120,
            'scopes' => ['licensing:account-link'],
        ]);
        $links = app(PlatformAccountLinkService::class);
        $link = $links->request($client, $license->uuid, (string) Str::uuid());
        $client->forceFill(['scopes' => ['mcp:read']])->save();
        try {
            $links->issueEnrollmentCode($client, $link['uuid']);
            $this->fail('A credential without the account-link scope must not issue an enrollment code.');
        } catch (PlatformAccountLinkException $exception) {
            $this->assertSame('insufficient_scope', $exception->errorCode);
        }
        $client->forceFill(['scopes' => ['licensing:account-link']])->save();
        LicensePlatformAccountLink::query()->where('uuid', $link['uuid'])->update(['status' => 'revoked']);
        try {
            $links->issueEnrollmentCode($client, $link['uuid']);
            $this->fail('A revoked link must not issue an enrollment code.');
        } catch (PlatformAccountLinkException $exception) {
            $this->assertSame('platform_account_not_approved', $exception->errorCode);
        }
        $this->assertSame(0, app(InstallationSeatAllocator::class)->used($license));
    }

    public function test_replacing_an_unused_code_reuses_its_seat_and_revokes_the_old_code(): void
    {
        [, $license] = $this->licenseFixture(1);
        $service = app(InstallationActivationService::class);
        $first = $service->issueCode($license);
        $first['activation_code']->forceFill(['expires_at' => now()->subMinute()])->save();
        $replacement = $service->issueCode($license, null, $first['activation_code']->uuid);

        $this->assertSame('revoked', $first['activation_code']->fresh()->status);
        $this->assertSame($replacement['activation_code']->id, $first['activation_code']->fresh()->replaced_by_id);
        $this->assertSame($first['activation_code']->id, $replacement['activation_code']->replacement_of_id);
        $this->assertNotSame($first['code'], $replacement['code']);

        $this->expectException(ValidationException::class);
        $service->issueCode($license);
    }

    public function test_a_redeemed_code_cannot_be_replaced(): void
    {
        [$product, $license] = $this->licenseFixture(1);
        $service = app(InstallationActivationService::class);
        $issued = $service->issueCode($license);
        $service->exchange([
            'activation_code' => $issued['code'],
            'product_code' => $product->code,
            'installation_uuid' => (string) Str::uuid(),
        ]);

        $this->expectException(ValidationException::class);
        $service->issueCode($license, null, $issued['activation_code']->uuid);
    }

    public function test_a_reserved_code_prevents_legacy_activation_from_overselling_the_same_seat(): void
    {
        [$product, $license] = $this->licenseFixture(1);
        $product->forceFill(['licensing_mode' => 'hybrid'])->save();
        app(InstallationActivationService::class)->issueCode($license);
        $legacyKey = 'LEGACY-ACTIVATION';
        $license->forceFill([
            'key_lookup' => hash_hmac('sha256', $legacyKey, (string) config('app.key')),
            'key_hash' => Hash::make($legacyKey),
            'metadata' => ['encrypted_license_key' => Crypt::encryptString($legacyKey)],
        ])->save();
        $client = ApiClient::query()->create([
            'uuid' => (string) Str::uuid(),
            'product_id' => $product->id,
            'name' => 'Legacy product client',
            'is_active' => true,
            'rate_limit_per_minute' => 120,
        ]);

        $this->expectException(ValidationException::class);
        app(LicenseService::class)->activate($client, [
            'license_key' => $legacyKey,
            'device_id' => 'legacy-workstation',
        ], '127.0.0.1');
    }

    public function test_direct_mode_rejects_new_legacy_key_activations(): void
    {
        [$product, $license] = $this->licenseFixture();
        $product->forceFill(['licensing_mode' => 'hybrid'])->save();
        $legacyKey = 'LEGACY-ACTIVATION';
        $license->forceFill([
            'key_lookup' => hash_hmac('sha256', $legacyKey, (string) config('app.key')),
            'key_hash' => Hash::make($legacyKey),
        ])->save();
        $client = ApiClient::query()->create([
            'uuid' => (string) Str::uuid(),
            'product_id' => $product->id,
            'name' => 'Legacy product client',
            'is_active' => true,
            'rate_limit_per_minute' => 120,
        ]);
        $service = app(LicenseService::class);
        $service->activate($client, [
            'license_key' => $legacyKey,
            'device_id' => 'existing-legacy-installation',
        ], '127.0.0.1');
        $product->forceFill(['licensing_mode' => 'direct'])->save();

        $existing = $service->activate($client, [
            'license_key' => $legacyKey,
            'device_id' => 'existing-legacy-installation',
        ], '127.0.0.1');
        $this->assertSame('active', $existing['status']);

        try {
            $service->activate($client, [
                'license_key' => $legacyKey,
                'device_id' => 'new-legacy-installation',
            ], '127.0.0.1');
            $this->fail('Direct mode must reject new reusable-key activations.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('configured for this product', $exception->getMessage());
        }

        try {
            $service->claim($client, $legacyKey, (string) Str::uuid());
            $this->fail('Direct mode must reject new platform-account claims.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('configured for direct', $exception->getMessage());
        }

        $product->forceFill(['licensing_mode' => 'platform_account'])->save();
        try {
            $service->claim($client, $legacyKey, (string) Str::uuid());
            $this->fail('Platform-account mode must require verified account ownership.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('ownership verification is required', $exception->getMessage());
        }
    }

    private function licenseFixture(int $limit = 2): array
    {
        $product = Product::factory()->create(['code' => 'DIRECT']);
        $product->forceFill(['licensing_mode' => 'direct', 'runtime_type' => 'laravel'])->save();
        $plan = Plan::factory()->create(['product_id' => $product->id, 'slug' => 'direct-installation-test']);
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
            'grace_ends_at' => null,
            'created_by' => $user->id,
        ]);
        $licenseKey = 'KBT-'.strtoupper(Str::random(8).'-'.Str::random(8).'-'.Str::random(8));
        $license = SoftwareLicense::factory()->create([
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'active',
            'expires_at' => now()->addMonth(),
            'key_lookup' => hash_hmac('sha256', $licenseKey, (string) config('app.key')),
            'key_hash' => Hash::make($licenseKey),
            'metadata' => ['encrypted_license_key' => Crypt::encryptString($licenseKey)],
        ]);
        SubscriptionEntitlement::query()->create([
            'subscription_id' => $subscription->id,
            'feature_key' => 'max_server_installations',
            'feature_name' => 'Installations',
            'enabled' => true,
            'limit_value' => (string) $limit,
            'source_plan_id' => $plan->id,
            'effective_from' => now()->subDay(),
        ]);

        return [$product, $license];
    }
}
