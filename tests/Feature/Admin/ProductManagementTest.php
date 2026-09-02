<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_administrator_can_manage_products_and_related_catalogue_data(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)->get(route('admin.products.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Products/Create'));
        $this->actingAs($admin)->post(route('admin.products.store'), $this->payload())->assertRedirect();

        $product = Product::query()->where('code', 'BRIDGECRM')->firstOrFail();
        $this->assertNotNull($product->uuid);
        $this->assertCount(1, $product->productFeatures);
        $this->assertCount(1, $product->media);
        $this->assertCount(1, $product->links);
        $this->assertDatabaseHas('audit_logs', ['event' => 'product.created', 'actor_id' => $admin->id]);

        $payload = $this->payload();
        $payload['name'] = 'Bridge CRM Pro';
        $payload['features'] = [['name' => 'Pipeline management', 'description' => null, 'icon' => null, 'is_active' => true]];
        $this->actingAs($admin)->put(route('admin.products.update', $product), $payload)->assertRedirect(route('admin.products.show', $product));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Bridge CRM Pro']);
        $this->assertDatabaseHas('product_features', ['product_id' => $product->id, 'name' => 'Pipeline management']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'product.updated', 'actor_id' => $admin->id]);

        $this->actingAs($admin)->delete(route('admin.products.destroy', $product))->assertRedirect(route('admin.products.index'));
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'product.deleted', 'actor_id' => $admin->id]);
    }

    public function test_active_product_and_normalized_relations_load_on_public_page(): void
    {
        $this->actingAs($this->administrator())->post(route('admin.products.store'), $this->payload());

        $this->get(route('products.show', 'bridge-crm'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Public/Products/Show')
            ->where('product.name', 'Bridge CRM')
            ->where('product.features.0', 'Lead management')
            ->where('product.screenshots.0', 'https://cdn.example.com/dashboard.webp'));
    }

    public function test_product_permissions_are_enforced_for_admin_routes(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole(UserRole::Customer->value);
        $product = Product::query()->create($this->payload());

        $this->actingAs($customer)->get(route('admin.products.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.products.create'))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.products.edit', $product))->assertForbidden();
        $this->actingAs($customer)->delete(route('admin.products.destroy', $product))->assertForbidden();
    }

    public function test_status_and_unique_identifiers_are_validated(): void
    {
        $admin = $this->administrator();
        Product::query()->create($this->payload());
        $payload = $this->payload();
        $payload['status'] = 'published';

        $this->actingAs($admin)->post(route('admin.products.store'), $payload)->assertSessionHasErrors(['code', 'slug', 'status']);
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
            'name' => 'Bridge CRM', 'code' => 'BRIDGECRM', 'slug' => 'bridge-crm', 'category' => 'Business Operations', 'industry' => 'Business',
            'tagline' => 'Connected customer operations.', 'short_description' => 'A modern CRM platform.', 'hero_description' => 'Manage the complete customer lifecycle.', 'description' => 'A complete CRM product for growing businesses.',
            'icon' => 'crm', 'logo' => '/images/products/bridge-crm.svg', 'cover_image' => '/images/products/bridge-crm-cover.webp', 'demo_url' => 'https://demo.example.com', 'website_url' => 'https://product.example.com',
            'status' => 'active', 'is_featured' => true, 'sort_order' => 10, 'metadata' => ['tier' => 'commercial'], 'seo_title' => 'Bridge CRM', 'seo_description' => 'Customer relationship management software.',
            'features' => [['name' => 'Lead management', 'description' => 'Track qualified leads.', 'icon' => 'users', 'is_active' => true]],
            'media' => [['type' => 'screenshot', 'title' => 'Dashboard', 'url' => 'https://cdn.example.com/dashboard.webp', 'alt_text' => 'Bridge CRM dashboard', 'is_active' => true]],
            'links' => [['type' => 'documentation', 'label' => 'Documentation', 'url' => 'https://docs.example.com', 'is_active' => true]],
        ];
    }
}
