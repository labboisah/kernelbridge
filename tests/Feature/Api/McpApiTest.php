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
use Tests\TestCase;

final class McpApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class, DemoProfileSeeder::class, PlanSeeder::class]);
    }

    public function test_regular_product_credentials_cannot_use_mcp_endpoints(): void
    {
        [, $token] = $this->credential(['*']);

        $this->withToken($token)->getJson('/api/v1/mcp/customers/search?query=ABC')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'insufficient_scope');
    }

    public function test_mcp_read_scope_can_search_customers_and_audits_read(): void
    {
        [$client, $token] = $this->credential(['mcp:read']);
        $this->subscription();

        $this->withToken($token)->getJson('/api/v1/mcp/customers/search?query=ABC')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'ABC Academy')
            ->assertJsonMissingPath('data.0.id');

        $this->assertDatabaseHas('mcp_audit_logs', [
            'api_client_id' => $client->id,
            'tool_name' => 'kernelbridge_find_customer',
            'mutation' => false,
            'target_type' => 'customer_search',
        ]);
    }

    public function test_mcp_read_scope_can_return_setup_without_raw_api_token(): void
    {
        [, $token] = $this->credential(['mcp:read']);
        $subscription = $this->subscription();
        app(LicenseService::class)->issue($subscription);

        $this->withToken($token)->getJson('/api/v1/mcp/services/'.$subscription->uuid.'/setup?runtime=laravel')
            ->assertOk()
            ->assertJsonPath('data.product_code', 'ALIGNEX')
            ->assertJsonPath('data.api_token', 'replace-with-product-machine-token')
            ->assertJsonPath('data.has_license', true);
    }

    public function test_mcp_write_scope_can_issue_license_and_audits_mutation(): void
    {
        [$client, $token] = $this->credential(['mcp:write']);
        $subscription = $this->subscription();

        $this->withToken($token)->postJson('/api/v1/mcp/licenses/issue', [
            'subscription_uuid' => $subscription->uuid,
            'max_activations' => 2,
            'confirmed_by' => 'Support Manager',
            'confirmation_note' => 'Customer payment and subscription were verified.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.max_activations', 2)
            ->assertJsonStructure(['data' => ['license_uuid', 'license_key', 'confirmation_uuid']]);

        $this->assertDatabaseHas('mcp_audit_logs', [
            'api_client_id' => $client->id,
            'tool_name' => 'kernelbridge_issue_license',
            'mutation' => true,
            'target_type' => 'subscription',
            'target_uuid' => $subscription->uuid,
        ]);
        $this->assertDatabaseHas('mcp_tool_confirmations', ['tool_name' => 'kernelbridge_issue_license', 'target_uuid' => $subscription->uuid, 'confirmed_by' => 'Support Manager']);
    }

    public function test_mcp_write_scope_can_deactivate_activation_and_audits_mutation(): void
    {
        [, $token] = $this->credential(['mcp:write']);
        $subscription = $this->subscription();
        $issued = app(LicenseService::class)->issue($subscription);
        $license = $issued['license'];
        $activation = $license->activations()->create(['device_fingerprint' => hash('sha256', 'server-1'), 'device_name' => 'Server 1', 'status' => 'active', 'activated_at' => now(), 'last_verified_at' => now()]);

        $this->withToken($token)->postJson('/api/v1/mcp/license-activations/'.$activation->uuid.'/deactivate', [
            'reason' => 'Retired server',
            'confirmed_by' => 'Support Manager',
            'confirmation_note' => 'Customer confirmed this server was retired.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'deactivated')
            ->assertJsonStructure(['data' => ['confirmation_uuid']]);

        $this->assertDatabaseHas('license_activations', ['uuid' => $activation->uuid, 'status' => 'deactivated']);
        $this->assertDatabaseHas('mcp_audit_logs', ['tool_name' => 'kernelbridge_deactivate_license_activation', 'mutation' => true, 'target_uuid' => $activation->uuid]);
        $this->assertDatabaseHas('mcp_tool_confirmations', ['tool_name' => 'kernelbridge_deactivate_license_activation', 'target_uuid' => $activation->uuid, 'confirmed_by' => 'Support Manager']);
    }

    public function test_mcp_write_tools_require_human_confirmation(): void
    {
        [, $token] = $this->credential(['mcp:write']);
        $subscription = $this->subscription();

        $this->withToken($token)->postJson('/api/v1/mcp/licenses/issue', ['subscription_uuid' => $subscription->uuid])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['confirmed_by', 'confirmation_note']]]);
    }

    public function test_mcp_tool_rate_limits_are_enforced_per_tool(): void
    {
        [, $token] = $this->credential(['mcp:write']);
        $subscription = $this->subscription();

        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)->postJson('/api/v1/mcp/licenses/issue', [
                'subscription_uuid' => $subscription->uuid,
                'confirmed_by' => 'Support Manager',
                'confirmation_note' => 'Approved test request '.$i,
            ])->assertStatus($i === 0 ? 201 : 200);
        }

        $this->withToken($token)->postJson('/api/v1/mcp/licenses/issue', [
            'subscription_uuid' => $subscription->uuid,
            'confirmed_by' => 'Support Manager',
            'confirmation_note' => 'One request too many.',
        ])->assertStatus(429)->assertJsonPath('error.code', 'mcp_tool_rate_limited');
    }

    public function test_mcp_scope_is_product_scoped(): void
    {
        [, $token] = $this->credential(['mcp:read']);
        $otherSubscription = $this->subscription('EDUNEXA', 'Other School', 'other@example.com');

        $this->withToken($token)->getJson('/api/v1/mcp/services/'.$otherSubscription->uuid.'/setup?runtime=django')
            ->assertNotFound();
    }

    private function credential(array $scopes): array
    {
        $product = Product::query()->where('code', 'ALIGNEX')->firstOrFail();
        $service = app(ApiCredentialService::class);
        /** @var ApiClient $client */
        $client = $service->createClient($product, 'AlignEx MCP integration', ['scopes' => $scopes]);
        $issued = $service->issue($client, 'mcp-test');

        return [$client, $issued['token'], $issued['credential']];
    }

    private function subscription(string $productCode = 'ALIGNEX', string $customerName = 'ABC Academy', string $email = 'api@example.com'): Subscription
    {
        $plan = Plan::query()->where('slug', 'professional')->whereHas('product', fn ($query) => $query->where('code', $productCode))->firstOrFail();
        $user = User::factory()->create();
        $user->assignRole(UserRole::Admin->value);
        $customer = Customer::query()->create(['customer_number' => 'KBT-CUS-'.strtoupper(substr(md5($email), 0, 6)), 'type' => 'organization', 'name' => $customerName, 'email' => $email, 'status' => 'active']);

        return Subscription::query()->create(['customer_id' => $customer->id, 'product_id' => $plan->product_id, 'plan_id' => $plan->id, 'subscription_number' => 'KBT-SUB-'.strtoupper(substr(md5($email), 0, 8)), 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addYear(), 'auto_renew' => true, 'price' => $plan->price, 'currency' => $plan->currency, 'billing_cycle' => $plan->billing_cycle, 'created_by' => $user->id]);
    }
}
