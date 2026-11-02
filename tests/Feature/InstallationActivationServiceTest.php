<?php

namespace Tests\Feature;

use App\Domain\Licensing\Services\InstallationActivationService;
use App\Domain\Licensing\Services\LicenseService;
use App\Domain\Licensing\Exceptions\InstallationVerificationException;
use App\Domain\Integration\Services\ApiCredentialService;
use App\Models\ApiClient;
use App\Models\Customer;
use App\Models\LicensePlatformAccountLink;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;
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

    public function test_platform_backend_request_requires_customer_approval_before_bound_installation_code_issuance(): void
    {
        [$product, $license] = $this->licenseFixture();
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
        $pending = $this->withToken($issuedCredential['token'])->postJson('/api/v1/platform/account-links/requests', [
            'license_uuid' => $license->uuid,
            'business_uuid' => $businessUuid,
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->json('data');

        $owner = User::factory()->create();
        $license->subscription->customer->users()->attach($owner->id, ['is_primary' => true]);
        $member = User::factory()->create();
        $license->subscription->customer->users()->attach($member->id, ['is_primary' => false]);
        $this->actingAs($member)->post(route('portal.platform-account-links.approve', $pending['uuid']))
            ->assertForbidden();

        $installationUuid = (string) Str::uuid();
        $issueRequest = [
            'installation_uuid' => $installationUuid,
            'device_name' => 'Clinic server',
        ];
        $this->withToken($issuedCredential['token'])
            ->postJson('/api/v1/platform/account-links/'.$pending['uuid'].'/activation-codes', $issueRequest)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'platform_account_not_approved');

        $this->actingAs($owner)->post(route('portal.platform-account-links.approve', $pending['uuid']))
            ->assertRedirect();

        $codeEndpoint = '/api/v1/platform/account-links/'.$pending['uuid'].'/activation-codes';
        $firstCode = $this->withToken($issuedCredential['token'])->postJson($codeEndpoint, $issueRequest)
            ->assertCreated()
            ->assertJsonPath('data.installation_uuid', $installationUuid)
            ->json('data.activation_code');
        $code = $this->withToken($issuedCredential['token'])->postJson($codeEndpoint, $issueRequest)
            ->assertCreated()
            ->json('data.activation_code');
        $this->assertNotSame($firstCode, $code);
        $this->assertSame(1, app(\App\Domain\Licensing\Services\InstallationSeatAllocator::class)->used($license));

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
        $linkId = LicensePlatformAccountLink::query()->where('uuid', $pending['uuid'])->value('id');
        $this->assertDatabaseHas('license_installations', [
            'installation_uuid' => $installationUuid,
            'business_uuid' => strtolower($businessUuid),
            'license_platform_account_link_id' => $linkId,
        ]);
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

        $this->assertSame(1, app(\App\Domain\Licensing\Services\InstallationSeatAllocator::class)->used($license));

        $this->withToken($activation['credential'])->postJson('/api/v1/installations/deactivate')
            ->assertOk()
            ->assertJsonPath('data.status', 'deactivated')
            ->assertJsonPath('data.installation_uuid', $activation['installation_uuid']);

        $this->withToken($activation['credential'])->postJson('/api/v1/installations/deactivate')
            ->assertOk()
            ->assertJsonPath('data.status', 'deactivated');

        $this->assertSame(0, app(\App\Domain\Licensing\Services\InstallationSeatAllocator::class)->used($license));
        $this->withToken($activation['credential'])->postJson('/api/v1/installations/verify')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'installation_deactivated');
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'license.installation.deactivated',
            'entity_type' => \App\Models\LicenseInstallation::class,
        ]);
        $this->assertNotNull($service->authenticate($activation['credential']));
    }

    public function test_replacing_an_unused_code_reuses_its_seat_and_revokes_the_old_code(): void
    {
        [, $license] = $this->licenseFixture(1);
        $service = app(InstallationActivationService::class);
        $first = $service->issueCode($license);
        $replacement = $service->issueCode($license, null, $first['activation_code']->uuid);

        $this->assertSame('revoked', $first['activation_code']->fresh()->status);
        $this->assertSame($replacement['activation_code']->id, $first['activation_code']->fresh()->replaced_by_id);
        $this->assertSame($first['activation_code']->id, $replacement['activation_code']->replacement_of_id);
        $this->assertNotSame($first['code'], $replacement['code']);

        $this->expectException(ValidationException::class);
        $service->issueCode($license);
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
        $license = SoftwareLicense::factory()->create([
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'active',
            'expires_at' => now()->addMonth(),
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
