<?php

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_the_public_application_loads(): void
    {
        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Public/Home')->has('products', 5)->where('activeProductCount', 5)
        );
    }

    public function test_the_versioned_api_health_endpoint_loads(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.version', 'v1')->assertJsonPath('data.status', 'ok');
    }

    public function test_public_corporate_pages_load(): void
    {
        foreach (['/products', '/products/alignex', '/services', '/about', '/contact', '/demo', '/docs/licensing', '/login', '/register'] as $uri) {
            $this->get($uri)->assertOk();
        }
    }

    public function test_public_licensing_documentation_uses_the_documented_component(): void
    {
        $this->get(route('docs.licensing'))->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Public/Docs/Licensing')
        );
    }

    public function test_contact_inquiries_are_validated_and_stored(): void
    {
        $this->post('/contact', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'subject' => 'Platform enquiry', 'message' => 'We would like to discuss a new platform.'])->assertSessionHas('success');
        $this->assertDatabaseHas('contact_inquiries', ['email' => 'ada@example.com', 'status' => 'new']);
    }

    public function test_demo_requests_are_validated_and_stored(): void
    {
        $product = Product::query()->where('slug', 'staycontrol')->firstOrFail();
        $this->post('/demo', ['product_id' => $product->public_id, 'name' => 'Grace Hopper', 'email' => 'grace@example.com', 'company' => 'Example LTD'])->assertSessionHas('success');
        $this->assertDatabaseHas('demo_requests', ['email' => 'grace@example.com', 'product_id' => $product->id, 'status' => 'pending']);
    }

    public function test_staycontrol_is_seeded_as_an_active_featured_product(): void
    {
        $this->seed(ProductSeeder::class);

        $this->assertDatabaseCount('products', 5);
        $this->assertDatabaseHas('products', ['code' => 'STAYCONTROL', 'slug' => 'staycontrol', 'status' => 'active', 'is_featured' => true]);
    }

    public function test_staycontrol_appears_in_the_catalogue_with_its_demo_url(): void
    {
        $this->get('/products')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Public/Products/Index')
            ->has('products', 5)
            ->where('products.4.slug', 'staycontrol')
            ->where('products.4.demo_url', 'https://staycontrol.kernelbridge.com'));
    }

    public function test_staycontrol_product_page_loads(): void
    {
        $this->get('/products/staycontrol')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Public/Products/Show')
            ->where('product.code', 'STAYCONTROL')
            ->where('product.status', 'active'));
    }

    public function test_demo_page_preselects_valid_staycontrol_and_rejects_invalid_products(): void
    {
        $stayControl = Product::query()->where('slug', 'staycontrol')->firstOrFail();

        $this->get('/demo?product=staycontrol')->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedProductId', $stayControl->public_id));
        $this->get('/demo?product=not-a-product')->assertNotFound();
        $this->post('/demo', ['product_id' => '01INVALIDPRODUCT000000000000', 'name' => 'Invalid Product', 'email' => 'invalid@example.com', 'company' => 'Example LTD'])->assertSessionHasErrors('product_id');
    }

    public function test_inactive_products_are_not_counted_or_displayed(): void
    {
        Product::query()->create(['code' => 'INACTIVE', 'name' => 'Inactive', 'slug' => 'inactive', 'tagline' => 'Inactive product', 'short_description' => 'Inactive product', 'description' => 'Not public.', 'industry' => 'Test', 'icon' => 'test', 'features' => [], 'is_featured' => true, 'sort_order' => 99, 'status' => 'archived']);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('activeProductCount', 5)
            ->has('products', 5));
        $this->get('/products/inactive')->assertNotFound();
    }
}
