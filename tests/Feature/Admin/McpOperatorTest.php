<?php

namespace Tests\Feature\Admin;

use App\Domain\Integration\Services\ApiCredentialService;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class McpOperatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class, DemoProfileSeeder::class, PlanSeeder::class]);
    }

    public function test_admin_can_view_mcp_operator_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.mcp.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Mcp/Index')
                ->has('products')
                ->has('presets.support_read')
                ->where('apiUrl', url('/api/v1'))
            );
    }

    public function test_mcp_client_can_be_created_next_to_regular_product_client(): void
    {
        $admin = $this->admin();
        $product = Product::query()->where('code', 'ALIGNEX')->firstOrFail();
        app(ApiCredentialService::class)->createClient($product, 'AlignEx integration');

        $this->actingAs($admin)->post(route('admin.mcp.clients.store'), [
            'product_uuid' => $product->uuid,
            'preset' => 'license_operator',
            'name' => 'AlignEx MCP operators',
            'credential_name' => 'ops primary',
            'expires_at' => now()->addMonth()->toDateString(),
            'rate_limit_per_minute' => 90,
            'allowed_ips' => "127.0.0.1\n10.0.0.5",
        ])->assertRedirect()->assertSessionHas('mcp_token');

        $this->assertDatabaseHas('api_clients', ['product_id' => $product->id, 'name' => 'AlignEx integration']);
        $this->assertDatabaseHas('api_clients', ['product_id' => $product->id, 'name' => 'AlignEx MCP operators']);
        $client = \App\Models\ApiClient::query()->where('product_id', $product->id)->where('name', 'AlignEx MCP operators')->firstOrFail();
        $this->assertSame(['mcp:read', 'mcp:write'], $client->scopes);
        $this->assertSame(['127.0.0.1', '10.0.0.5'], $client->allowed_ips);
    }

    public function test_admin_can_rotate_and_revoke_mcp_credentials(): void
    {
        $admin = $this->admin();
        $product = Product::query()->where('code', 'ALIGNEX')->firstOrFail();
        $service = app(ApiCredentialService::class);
        $client = $service->createClient($product, 'AlignEx MCP support', ['scopes' => ['mcp:read']]);
        $issued = $service->issue($client, 'primary', $admin);

        $this->actingAs($admin)->post(route('admin.mcp.credentials.rotate', $issued['credential']->uuid), [
            'name' => 'rotated primary',
            'expires_at' => now()->addWeeks(2)->toDateString(),
        ])->assertRedirect()->assertSessionHas('mcp_token');

        $this->assertNotNull($issued['credential']->fresh()->revoked_at);
        $replacement = $client->credentials()->where('name', 'rotated primary')->firstOrFail();
        $this->assertNull($replacement->revoked_at);

        $this->post(route('admin.mcp.credentials.revoke', $replacement->uuid))->assertRedirect();
        $this->assertNotNull($replacement->fresh()->revoked_at);
    }

    public function test_audit_logs_can_be_filtered_by_mutation_and_tool(): void
    {
        $admin = $this->admin();
        $subscription = $this->subscription();
        $product = $subscription->product;
        $client = app(ApiCredentialService::class)->createClient($product, 'AlignEx MCP support', ['scopes' => ['mcp:read']]);
        $credential = app(ApiCredentialService::class)->issue($client, 'primary', $admin)['credential'];

        DB::table('mcp_audit_logs')->insert([
            'uuid' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
            'api_client_id' => $client->id,
            'api_client_credential_id' => $credential->id,
            'tool_name' => 'kernelbridge_issue_license',
            'mutation' => true,
            'target_type' => 'subscription',
            'target_uuid' => $subscription->uuid,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'metadata' => json_encode([]),
            'created_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.mcp.index', ['mutation' => 'true', 'tool' => 'kernelbridge_issue_license']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Mcp/Index')
                ->has('auditLogs', 1)
                ->where('auditLogs.0.tool_name', 'kernelbridge_issue_license')
                ->where('auditLogs.0.mutation', true)
            );
    }

    public function test_customer_users_cannot_access_mcp_operator_page(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole(UserRole::Customer->value);

        $this->actingAs($customer)->get(route('admin.mcp.index'))->assertForbidden();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin->value);

        return $admin;
    }

    private function subscription(): Subscription
    {
        $plan = Plan::query()->where('slug', 'professional')->whereHas('product', fn ($query) => $query->where('code', 'ALIGNEX'))->firstOrFail();
        $customer = Customer::query()->create(['customer_number' => 'KBT-CUS-MCP001', 'type' => 'organization', 'name' => 'ABC Academy', 'email' => 'mcp@example.com', 'status' => 'active']);

        return Subscription::query()->create(['customer_id' => $customer->id, 'product_id' => $plan->product_id, 'plan_id' => $plan->id, 'subscription_number' => 'KBT-SUB-MCP001', 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addYear(), 'auto_renew' => true, 'price' => $plan->price, 'currency' => $plan->currency, 'billing_cycle' => $plan->billing_cycle, 'created_by' => $this->admin()->id]);
    }
}



