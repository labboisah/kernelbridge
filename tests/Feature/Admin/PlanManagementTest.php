<?php

namespace Tests\Feature\Admin;

use App\Domain\Subscriptions\Services\PlanEntitlementService;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class PlanManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, ProductSeeder::class, PlanSeeder::class]);
    }

    public function test_alignex_plans_and_entitlements_are_seeded(): void
    {
        $professional = Plan::query()->where('slug', 'professional')->firstOrFail();
        $service = app(PlanEntitlementService::class);

        $this->assertSame(3, Plan::query()->whereBelongsTo($professional->product)->count());
        $this->assertTrue($service->contains($professional, 'sms_enabled'));
        $this->assertFalse($service->contains($professional, 'inventory_enabled'));
        $this->assertSame(25, $service->limit($professional, 'max_users'));
        $this->assertNull($service->limit($professional, 'missing_limit'));
        $this->assertDatabaseHas('product_plan', ['product_id' => $professional->product_id, 'plan_id' => $professional->id]);
    }

    public function test_administrator_can_manage_plans_with_features_limits_and_audit_logs(): void
    {
        $admin = $this->administrator();
        $product = Product::query()->where('code', 'EDUNEXA')->firstOrFail();

        $this->actingAs($admin)->get(route('admin.products.plans.create', $product))->assertOk();
        $this->actingAs($admin)->post(route('admin.products.plans.store', $product), $this->payload())->assertRedirect();
        $plan = Plan::query()->where('product_id', $product->id)->where('slug', 'growth')->firstOrFail();
        $this->assertCount(2, $plan->features);
        $this->assertCount(1, $plan->limits);
        $this->assertDatabaseHas('audit_logs', ['event' => 'plan.created', 'actor_id' => $admin->id]);

        $payload = $this->payload();
        $payload['name'] = 'Growth Plus';
        $payload['limits'][0]['limit_value'] = '20';
        $this->actingAs($admin)->put(route('admin.plans.update', $plan), $payload)->assertRedirect(route('admin.plans.show', $plan));
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'name' => 'Growth Plus']);
        $this->assertDatabaseHas('plan_limits', ['plan_id' => $plan->id, 'limit_key' => 'max_branches', 'limit_value' => '20']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'plan.updated', 'actor_id' => $admin->id]);

        $this->actingAs($admin)->delete(route('admin.plans.destroy', $plan))->assertRedirect(route('admin.products.plans.index', $product));
        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'plan.deleted', 'actor_id' => $admin->id]);
    }

    public function test_public_pricing_page_only_displays_active_public_database_plans(): void
    {
        $product = Product::query()->where('code', 'ALIGNEX')->firstOrFail();
        Plan::query()->where('slug', 'basic')->update(['is_public' => false]);
        Plan::query()->where('slug', 'professional')->update(['is_active' => false]);

        $this->get(route('products.pricing', $product))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Public/Products/Pricing')
            ->has('plans', 1)
            ->where('plans.0.name', 'Enterprise')
            ->has('plans.0.features', 3));
    }

    public function test_customers_cannot_manage_plans(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole(UserRole::Customer->value);
        $product = Product::query()->where('code', 'ALIGNEX')->firstOrFail();
        $plan = $product->subscriptionPlans()->firstOrFail();

        $this->actingAs($customer)->get(route('admin.products.plans.index', $product))->assertForbidden();
        $this->actingAs($customer)->post(route('admin.products.plans.store', $product), $this->payload())->assertForbidden();
        $this->actingAs($customer)->put(route('admin.plans.update', $plan), $this->payload())->assertForbidden();
        $this->actingAs($customer)->delete(route('admin.plans.destroy', $plan))->assertForbidden();
    }

    public function test_plan_input_requires_supported_billing_and_unique_feature_keys(): void
    {
        $payload = $this->payload();
        $payload['billing_cycle'] = 'weekly';
        $payload['features'][] = $payload['features'][0];

        $this->actingAs($this->administrator())
            ->post(route('admin.products.plans.store', Product::query()->where('code', 'EDUNEXA')->firstOrFail()), $payload)
            ->assertSessionHasErrors(['billing_cycle', 'features.0.feature_key', 'features.2.feature_key']);
    }

    private function administrator(): User
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::Admin->value);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'name' => 'Growth', 'slug' => 'growth', 'description' => 'For growing education organisations.',
            'billing_cycle' => 'monthly', 'price' => 50000, 'currency' => 'NGN', 'setup_fee' => 10000,
            'trial_days' => 14, 'grace_period_days' => 7, 'is_active' => true, 'is_public' => true, 'sort_order' => 2,
            'metadata' => ['recommended' => true],
            'features' => [
                ['feature_key' => 'finance_enabled', 'feature_name' => 'Finance management', 'enabled' => true, 'limit_value' => null],
                ['feature_key' => 'max_students', 'feature_name' => 'Students', 'enabled' => true, 'limit_value' => '5000'],
            ],
            'limits' => [['limit_key' => 'max_branches', 'limit_name' => 'Branches', 'limit_value' => '10', 'metadata' => []]],
        ];
    }
}
