<?php

namespace Tests\Feature\Api;

use App\Domain\Integration\Services\ApiCredentialService;
use App\Domain\Licensing\Services\LicenseService;
use App\Enums\UserRole;
use App\Models\ApiClient;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\DemoProfileSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InstallationVerificationTest extends TestCase
{
    use RefreshDatabase;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class, DemoProfileSeeder::class, PlanSeeder::class]);

        $pair = sodium_crypto_sign_keypair();
        $this->publicKey = sodium_crypto_sign_publickey($pair);
        config([
            'product_integration.signing.secret_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
            'product_integration.signing.key_id' => 'test-key',
        ]);
    }

    public function test_installation_receives_a_signed_entitlement_snapshot(): void
    {
        [$credential] = $this->issuedGrant();

        $data = $this->withToken($credential)->postJson('/api/v1/installations/verify')
            ->assertOk()
            ->assertJsonPath('data.algorithm', 'ed25519')
            ->assertJsonPath('data.key_id', 'test-key')
            ->json('data');

        $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($data['signature']), $data['payload_json'], $this->publicKey));

        $payload = json_decode($data['payload_json'], true);
        $this->assertSame('active', $payload['license_status']);
        $this->assertSame('ALIGNEX', $payload['product_code']);
        $this->assertContains('max_server_installations', array_column($payload['entitlements'], 'key'));
    }

    public function test_tampered_payload_fails_signature_verification(): void
    {
        [$credential] = $this->issuedGrant();
        $data = $this->withToken($credential)->postJson('/api/v1/installations/verify')->json('data');

        $tampered = str_replace('"active"', '"suspended"', $data['payload_json']);

        $this->assertNotSame($data['payload_json'], $tampered);
        $this->assertFalse(sodium_crypto_sign_verify_detached(base64_decode($data['signature']), $tampered, $this->publicKey));
    }

    public function test_invalid_revoked_and_unconfigured_cases_are_rejected(): void
    {
        [$credential, $grantUuid, $apiToken] = $this->issuedGrant();

        $this->withToken($grantUuid.'.wrong-secret')->postJson('/api/v1/installations/verify')
            ->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credential');
        $this->postJson('/api/v1/installations/verify')->assertUnauthorized();

        $this->withToken($apiToken)->postJson('/api/v1/licenses/installation-grants/'.$grantUuid.'/revoke')->assertOk();
        $this->withToken($credential)->postJson('/api/v1/installations/verify')
            ->assertForbidden()->assertJsonPath('error.code', 'grant_revoked');

        config(['product_integration.signing.secret_key' => null]);
        $this->withToken($credential)->postJson('/api/v1/installations/verify')
            ->assertStatus(503)->assertJsonPath('error.code', 'signing_not_configured');
    }

    public function test_subscription_changes_send_a_signed_webhook(): void
    {
        Http::fake(['platform.test/*' => Http::response(['ok' => true])]);
        $subscription = $this->subscription();
        app(LicenseService::class)->issue($subscription, 1);
        config(['product_integration.webhooks.ALIGNEX' => ['url' => 'https://platform.test/webhooks/kernelbridge', 'secret' => 'webhook-secret']]);

        $subscription->entitlements()->create(['feature_key' => 'mode_sales', 'feature_name' => 'Sales', 'enabled' => true, 'limit_value' => null, 'source_plan_id' => $subscription->plan_id, 'effective_from' => now()]);

        Http::assertSent(function ($request): bool {
            preg_match('/t=(\d+),v1=([a-f0-9]+)/', $request->header('X-KernelBridge-Signature')[0] ?? '', $match);
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://platform.test/webhooks/kernelbridge'
                && $body['type'] === 'subscription.entitlements_changed'
                && isset($match[2])
                && hash_equals(hash_hmac('sha256', $match[1].'.'.$request->body(), 'webhook-secret'), $match[2]);
        });
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function issuedGrant(): array
    {
        $subscription = $this->subscription();
        $subscription->entitlements()->create(['feature_key' => 'max_server_installations', 'feature_name' => 'Business Server installations', 'enabled' => true, 'limit_value' => '2', 'source_plan_id' => $subscription->plan_id, 'effective_from' => now()]);
        $issued = app(LicenseService::class)->issue($subscription, 5);
        $businessUuid = (string) Str::uuid();
        $client = ApiClient::query()->where('product_id', $subscription->product_id)->first()
            ?? app(ApiCredentialService::class)->createClient(Product::query()->findOrFail($subscription->product_id), 'Verification test');
        $apiToken = app(ApiCredentialService::class)->issue($client, 'test')['token'];
        app(LicenseService::class)->claim($client, $issued['license_key'], $businessUuid);

        $grant = $this->withToken($apiToken)->postJson('/api/v1/licenses/installation-grants', [
            'license_uuid' => $issued['license']->uuid,
            'business_uuid' => $businessUuid,
            'branch_uuid' => (string) Str::uuid(),
            'device_uuid' => (string) Str::uuid(),
            'installation_uuid' => (string) Str::uuid(),
        ])->assertCreated()->json('data');

        return [$grant['credential'], $grant['grant_uuid'], $apiToken];
    }

    private function subscription(): Subscription
    {
        $plan = Plan::query()->where('slug', 'professional')->firstOrFail();
        $user = User::factory()->create();
        $user->assignRole(UserRole::Admin->value);
        $customer = Customer::query()->create(['customer_number' => 'KBT-CUS-000009', 'type' => 'organization', 'name' => 'Verify Academy', 'email' => 'verify@example.com', 'status' => 'active']);

        return Subscription::query()->create(['customer_id' => $customer->id, 'product_id' => $plan->product_id, 'plan_id' => $plan->id, 'subscription_number' => 'KBT-SUB-2026-000009', 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addYear(), 'auto_renew' => true, 'price' => $plan->price, 'currency' => $plan->currency, 'billing_cycle' => $plan->billing_cycle, 'created_by' => $user->id]);
    }
}
