<?php

namespace Tests\Feature\Api;

use App\Domain\Integration\Services\ApiCredentialService;
use App\Domain\Licensing\Services\LicenseService;
use App\Enums\UserRole;
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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductIntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class, DemoProfileSeeder::class, PlanSeeder::class]);
    }

    public function test_authentication_response_contract_security_headers_and_request_logging(): void
    {
        $this->getJson('/api/v1/products/ALIGNEX')->assertUnauthorized()->assertJsonPath('success', false)->assertJsonPath('error.code', 'unauthenticated')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY');
        $this->assertDatabaseCount('api_request_logs', 1);
        [$client, $token] = $this->credential();
        $response = $this->withToken($token)->getJson('/api/v1/products/ALIGNEX')->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.code', 'ALIGNEX');
        $this->assertTrue(Str::isUuid($response->json('meta.request_id')));
        $this->assertSame($response->json('meta.request_id'), $response->headers->get('X-Request-ID'));
        $this->assertArrayNotHasKey('id', $response->json('data'));
        $this->assertDatabaseHas('api_request_logs', ['api_client_id' => $client->id, 'status_code' => 200]);
    }

    public function test_product_credentials_are_isolated_and_validation_errors_are_consistent(): void
    {
        [, $token] = $this->credential();
        $this->withToken($token)->getJson('/api/v1/products/EDUNEXA')->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->withToken($token)->postJson('/api/v1/licenses/activate', [])->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['details' => ['license_key', 'device_id']], 'meta' => ['request_id']]);
    }

    public function test_license_activation_verification_and_deactivation_flow(): void
    {
        [, $token] = $this->credential();
        $subscription = $this->subscription();
        $issued = app(LicenseService::class)->issue($subscription, 1);
        $payload = ['license_key' => $issued['license_key'], 'device_id' => 'server-fingerprint-1', 'device_name' => 'ABC Production'];
        $activation = $this->withToken($token)->postJson('/api/v1/licenses/activate', $payload)->assertCreated()->assertJsonPath('data.status', 'active')->json('data');
        $this->assertTrue(Str::isUuid($activation['license_uuid']));
        $this->assertTrue(Str::isUuid($activation['activation_uuid']));
        $this->withToken($token)->postJson('/api/v1/licenses/verify', $payload)->assertOk()->assertJsonPath('data.activation_uuid', $activation['activation_uuid']);
        $this->withToken($token)->postJson('/api/v1/licenses/deactivate', $payload)->assertOk()->assertJsonPath('data.status', 'deactivated');
        $this->withToken($token)->postJson('/api/v1/licenses/verify', $payload)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_subscription_entitlements_and_demo_event_endpoints_expose_public_identifiers_only(): void
    {
        [, $token] = $this->credential();
        $subscription = $this->subscription();
        $subscription->entitlements()->create(['feature_key' => 'max_users', 'feature_name' => 'Users', 'enabled' => true, 'limit_value' => '25', 'source_plan_id' => $subscription->plan_id, 'effective_from' => now()]);
        $this->withToken($token)->getJson('/api/v1/subscriptions/status?subscription_uuid='.$subscription->uuid)->assertOk()->assertJsonPath('data.uuid', $subscription->uuid)->assertJsonMissingPath('data.customer_id')->assertJsonMissingPath('data.plan.id');
        $this->withToken($token)->getJson('/api/v1/entitlements?subscription_uuid='.$subscription->uuid)->assertOk()->assertJsonPath('data.entitlements.0.key', 'max_users')->assertJsonPath('data.entitlements.0.limit', '25');
        $session = $this->withToken($token)->postJson('/api/v1/demo/sessions', ['anonymous_identifier' => 'browser-123'])->assertCreated()->json('data.session_uuid');
        $event = ['session_uuid' => $session, 'event_id' => 'event-001', 'event_type' => 'workflow_completed', 'occurred_at' => now()->toISOString(), 'metadata' => ['tenant' => 'demo-school']];
        $this->withToken($token)->postJson('/api/v1/demo/events', $event)->assertStatus(202)->assertJsonPath('data.accepted', true);
        $this->withToken($token)->postJson('/api/v1/demo/events', $event)->assertStatus(202)->assertJsonPath('data.accepted', true);
        $this->assertDatabaseCount('demo_events', 1);
    }

    public function test_token_rotation_revocation_and_client_rate_limits_are_enforced(): void
    {
        [$client, $token, $credential] = $this->credential();
        $service = app(ApiCredentialService::class);
        $rotated = $service->rotate($credential, 'rotated');
        $this->withToken($token)->getJson('/api/v1/products/ALIGNEX')->assertUnauthorized()->assertJsonPath('error.code', 'invalid_token');
        $this->withToken($rotated['token'])->getJson('/api/v1/products/ALIGNEX')->assertOk();
        $service->revoke($rotated['credential']);
        $this->withToken($rotated['token'])->getJson('/api/v1/products/ALIGNEX')->assertUnauthorized();
        $client->update(['rate_limit_per_minute' => 1]);
        RateLimiter::clear('product-api:'.$client->uuid.':127.0.0.1');
        $limited = $service->issue($client, 'limited');
        $this->withToken($limited['token'])->getJson('/api/v1/products/ALIGNEX')->assertOk();
        $this->withToken($limited['token'])->getJson('/api/v1/products/ALIGNEX')->assertStatus(429)->assertJsonPath('error.code', 'rate_limit_exceeded');
    }

    private function credential(): array
    {
        $product = Product::query()->where('code', 'ALIGNEX')->firstOrFail();
        $service = app(ApiCredentialService::class);
        $client = $service->createClient($product, 'AlignEx integration');
        $issued = $service->issue($client, 'test');

        return [$client, $issued['token'], $issued['credential']];
    }

    private function subscription(): Subscription
    {
        $plan = Plan::query()->where('slug', 'professional')->firstOrFail();
        $user = User::factory()->create();
        $user->assignRole(UserRole::Admin->value);
        $customer = Customer::query()->create(['customer_number' => 'KBT-CUS-000001', 'type' => 'organization', 'name' => 'ABC Academy', 'email' => 'api@example.com', 'status' => 'active']);

        return Subscription::query()->create(['customer_id' => $customer->id, 'product_id' => $plan->product_id, 'plan_id' => $plan->id, 'subscription_number' => 'KBT-SUB-2026-000001', 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addYear(), 'auto_renew' => true, 'price' => $plan->price, 'currency' => $plan->currency, 'billing_cycle' => $plan->billing_cycle, 'created_by' => $user->id]);
    }
}
