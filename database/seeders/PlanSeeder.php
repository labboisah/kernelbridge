<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::query()->published()->with('productFeatures')->get();

        foreach ($products as $product) {
            foreach ($this->plansFor($product) as $index => $data) {
                $features = $data['features'];
                unset($data['features']);

                $plan = Plan::query()->updateOrCreate(
                    ['product_id' => $product->id, 'slug' => $data['slug']],
                    [...$data, 'currency' => 'NGN', 'setup_fee' => 0, 'trial_days' => 14, 'grace_period_days' => 7, 'is_active' => true, 'is_public' => true, 'sort_order' => $index + 1],
                );
                $plan->products()->syncWithoutDetaching([$product->id]);
                $plan->features()->delete();
                foreach ($features as $featureIndex => $feature) {
                    $plan->features()->create([...$feature, 'sort_order' => $featureIndex]);
                }
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function plansFor(Product $product): array
    {
        if ($product->code === 'ALIGNEX') {
            return $this->alignExPlans();
        }

        $capabilities = $product->productFeatures->pluck('name')->values();
        $feature = fn (string $name, bool $enabled = true): array => [
            'feature_key' => Str::snake($name), 'feature_name' => $name, 'enabled' => $enabled, 'limit_value' => null,
        ];
        $basic = $capabilities->take(2)->map(fn (string $name) => $feature($name))->all();
        $professional = $capabilities->map(fn (string $name) => $feature($name))->all();
        $enterprise = $professional;

        array_unshift($basic, ['feature_key' => 'max_users', 'feature_name' => 'Administrative users', 'enabled' => true, 'limit_value' => '5']);
        array_unshift($professional, ['feature_key' => 'max_users', 'feature_name' => 'Administrative users', 'enabled' => true, 'limit_value' => '25']);
        array_unshift($enterprise, ['feature_key' => 'max_users', 'feature_name' => 'Administrative users', 'enabled' => true, 'limit_value' => 'unlimited']);

        return [
            ['name' => 'Basic', 'slug' => 'basic', 'description' => "Essential {$product->name} workflows for smaller teams.", 'billing_cycle' => BillingCycle::Annual, 'price' => 75000, 'features' => $basic],
            ['name' => 'Professional', 'slug' => 'professional', 'description' => "Expanded {$product->name} capabilities for growing organizations.", 'billing_cycle' => BillingCycle::Annual, 'price' => 180000, 'features' => $professional],
            ['name' => 'Enterprise', 'slug' => 'enterprise', 'description' => "Custom capacity, integrations and implementation support for {$product->name}.", 'billing_cycle' => BillingCycle::Custom, 'price' => null, 'features' => $enterprise],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function alignExPlans(): array
    {
        return [
            ['name' => 'Basic', 'slug' => 'basic', 'description' => 'Core examination workflows for small institutions.', 'billing_cycle' => BillingCycle::Annual, 'price' => 75000, 'features' => [
                ['feature_key' => 'max_users', 'feature_name' => 'Administrative users', 'enabled' => true, 'limit_value' => '5'],
                ['feature_key' => 'max_candidates', 'feature_name' => 'Candidates', 'enabled' => true, 'limit_value' => '1000'],
                ['feature_key' => 'sms_enabled', 'feature_name' => 'SMS notifications', 'enabled' => false, 'limit_value' => null],
            ]],
            ['name' => 'Professional', 'slug' => 'professional', 'description' => 'Expanded capacity and communication tools for growing institutions.', 'billing_cycle' => BillingCycle::Annual, 'price' => 180000, 'features' => [
                ['feature_key' => 'max_users', 'feature_name' => 'Administrative users', 'enabled' => true, 'limit_value' => '25'],
                ['feature_key' => 'max_candidates', 'feature_name' => 'Candidates', 'enabled' => true, 'limit_value' => '10000'],
                ['feature_key' => 'sms_enabled', 'feature_name' => 'SMS notifications', 'enabled' => true, 'limit_value' => null],
            ]],
            ['name' => 'Enterprise', 'slug' => 'enterprise', 'description' => 'Custom capacity, integrations, and implementation support.', 'billing_cycle' => BillingCycle::Custom, 'price' => null, 'features' => [
                ['feature_key' => 'max_users', 'feature_name' => 'Administrative users', 'enabled' => true, 'limit_value' => 'unlimited'],
                ['feature_key' => 'max_candidates', 'feature_name' => 'Candidates', 'enabled' => true, 'limit_value' => 'unlimited'],
                ['feature_key' => 'sms_enabled', 'feature_name' => 'SMS notifications', 'enabled' => true, 'limit_value' => null],
            ]],
        ];
    }
}
