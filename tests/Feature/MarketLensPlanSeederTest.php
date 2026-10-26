<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Product;
use Database\Seeders\MarketLensPlanSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MarketLensPlanSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_plan_seeder_does_not_create_generic_marketlens_skus(): void
    {
        $this->seed([ProductSeeder::class, PlanSeeder::class]);

        $product = Product::query()->where('code', 'MARKETLENS')->firstOrFail();
        $this->assertSame(0, $product->subscriptionPlans()->whereIn('slug', ['basic', 'professional', 'enterprise'])->count());
    }

    public function test_it_seeds_the_approval_gated_marketlens_plans_and_feature_matrix(): void
    {
        $this->seed([ProductSeeder::class, PlanSeeder::class, MarketLensPlanSeeder::class]);

        $product = Product::query()->where('code', 'MARKETLENS')->firstOrFail();
        $plans = $product->subscriptionPlans()->whereIn('slug', [
            'marketlens-sales',
            'marketlens-production',
            'marketlens-services',
            'marketlens-complete',
        ])->with('features')->get()->keyBy('slug');

        $this->assertCount(4, $plans);
        $this->assertSame(4, Plan::publiclyAvailable()->where('product_id', $product->id)->count());
        $this->assertSame([
            'mode_sales' => true,
            'mode_production' => false,
            'mode_services' => false,
            'module_shareholders' => true,
            'max_server_installations' => true,
        ], $this->featureMatrix($plans->get('marketlens-sales')));
        $this->assertSame([
            'mode_sales' => false,
            'mode_production' => true,
            'mode_services' => false,
            'module_shareholders' => true,
            'max_server_installations' => true,
        ], $this->featureMatrix($plans->get('marketlens-production')));
        $this->assertSame([
            'mode_sales' => false,
            'mode_production' => false,
            'mode_services' => true,
            'module_shareholders' => true,
            'max_server_installations' => true,
        ], $this->featureMatrix($plans->get('marketlens-services')));
        $this->assertSame([
            'mode_sales' => true,
            'mode_production' => true,
            'mode_services' => true,
            'module_shareholders' => true,
            'max_server_installations' => true,
        ], $this->featureMatrix($plans->get('marketlens-complete')));

        foreach ($plans as $plan) {
            $this->assertNull($plan->price);
            $this->assertSame('custom', $plan->billing_cycle->value);
            $this->assertTrue($plan->is_active);
            $this->assertTrue($plan->is_public);
            $this->assertSame(0, $plan->trial_days);
            $this->assertSame(0, $plan->grace_period_days);
            $this->assertSame('NGN', $plan->currency);
            $this->assertSame([], $plan->limits()->get()->all());
            $this->assertSame('1', $plan->features()->where('feature_key', 'max_server_installations')->value('limit_value'));
        }
    }

    public function test_it_hides_legacy_generic_marketlens_plans_without_deactivating_them(): void
    {
        $this->seed(ProductSeeder::class);
        $product = Product::query()->where('code', 'MARKETLENS')->firstOrFail();

        foreach (['basic', 'professional', 'enterprise'] as $slug) {
            Plan::query()->create([
                'product_id' => $product->id,
                'name' => ucfirst($slug),
                'slug' => $slug,
                'billing_cycle' => 'annual',
                'price' => $slug === 'enterprise' ? null : 75000,
                'currency' => 'NGN',
                'setup_fee' => 0,
                'trial_days' => 14,
                'grace_period_days' => 7,
                'is_active' => true,
                'is_public' => true,
            ]);
        }

        $this->seed(MarketLensPlanSeeder::class);

        foreach (['basic', 'professional', 'enterprise'] as $slug) {
            $legacyPlan = Plan::query()->where('product_id', $product->id)->where('slug', $slug)->firstOrFail();
            $this->assertTrue($legacyPlan->is_active);
            $this->assertFalse($legacyPlan->is_public);
        }
    }

    public function test_rerunning_the_seeder_preserves_approved_catalogue_edits(): void
    {
        $this->seed([ProductSeeder::class, MarketLensPlanSeeder::class]);
        $plan = Plan::query()->where('slug', 'marketlens-sales')->firstOrFail();
        $plan->update(['price' => 12500, 'is_active' => false, 'is_public' => false]);
        $plan->features()->where('feature_key', 'mode_sales')->update(['enabled' => false]);
        $plan->features()->where('feature_key', 'max_server_installations')->update(['limit_value' => '5']);

        $this->seed(MarketLensPlanSeeder::class);

        $this->assertSame('12500.00', $plan->fresh()->price);
        $this->assertFalse($plan->fresh()->is_active);
        $this->assertFalse($plan->fresh()->is_public);
        $this->assertFalse((bool) $plan->fresh()->features()->where('feature_key', 'mode_sales')->value('enabled'));
        $this->assertSame('5', $plan->fresh()->features()->where('feature_key', 'max_server_installations')->value('limit_value'));
    }

    public function test_it_promotes_only_untouched_hidden_drafts_from_the_previous_seeder(): void
    {
        $this->seed([ProductSeeder::class, MarketLensPlanSeeder::class]);
        $plan = Plan::query()->where('slug', 'marketlens-sales')->firstOrFail();
        $plan->forceFill([
            'is_active' => false,
            'is_public' => false,
            'metadata' => [
                'catalogue_source' => 'marketlens_plan_seeder',
                'commercial_approval_required' => true,
            ],
        ])->save();

        $this->seed(MarketLensPlanSeeder::class);

        $this->assertTrue($plan->fresh()->is_active);
        $this->assertTrue($plan->fresh()->is_public);
        $this->assertNull($plan->fresh()->price);
        $this->assertTrue($plan->fresh()->metadata['catalogue_visibility_migrated']);
    }

    /** @return array<string, bool> */
    private function featureMatrix(Plan $plan): array
    {
        return $plan->features
            ->mapWithKeys(fn ($feature): array => [$feature->feature_key => $feature->enabled])
            ->all();
    }
}