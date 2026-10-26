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
            if ($product->code === 'MARKETLENS') {
                continue;
            }

            $definitions = $this->plansFor($product);
            if ($product->code === 'ALIGNEX') {
                $activeSlugs = array_column($definitions, 'slug');
                Plan::query()
                    ->where('product_id', $product->id)
                    ->whereNotIn('slug', $activeSlugs)
                    ->update(['is_active' => false, 'is_public' => false]);
            }

            foreach ($definitions as $index => $data) {
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
        $entities = [
            [
                'key' => 'secondary-school',
                'name' => 'Secondary School',
                'tiers' => [
                    ['key' => 'basic', 'name' => 'Basic', 'price' => 85000, 'admins' => '8', 'exams' => '25', 'candidates' => '2500', 'servers' => '1', 'sms' => false],
                    ['key' => 'standard', 'name' => 'Standard', 'price' => 165000, 'admins' => '20', 'exams' => '60', 'candidates' => '7000', 'servers' => '2', 'sms' => true],
                    ['key' => 'school', 'name' => 'School', 'price' => 260000, 'admins' => '40', 'exams' => '120', 'candidates' => '15000', 'servers' => '3', 'sms' => true],
                ],
            ],
            [
                'key' => 'institution',
                'name' => 'Institution',
                'tiers' => [
                    ['key' => 'basic', 'name' => 'Basic', 'price' => 150000, 'admins' => '20', 'exams' => '80', 'candidates' => '12000', 'servers' => '2', 'sms' => true],
                    ['key' => 'standard', 'name' => 'Standard', 'price' => 300000, 'admins' => '60', 'exams' => '200', 'candidates' => '50000', 'servers' => '4', 'sms' => true],
                    ['key' => 'enterprise', 'name' => 'Enterprise', 'price' => null, 'admins' => 'unlimited', 'exams' => 'unlimited', 'candidates' => 'unlimited', 'servers' => '8', 'sms' => true],
                ],
            ],
            [
                'key' => 'cbt-center',
                'name' => 'CBT Center',
                'tiers' => [
                    ['key' => 'basic', 'name' => 'Basic', 'price' => 125000, 'admins' => '15', 'exams' => '120', 'candidates' => '10000', 'servers' => '2', 'sms' => false],
                    ['key' => 'standard', 'name' => 'Standard', 'price' => 240000, 'admins' => '35', 'exams' => '260', 'candidates' => '30000', 'servers' => '3', 'sms' => true],
                    ['key' => 'enterprise', 'name' => 'Enterprise', 'price' => null, 'admins' => 'unlimited', 'exams' => 'unlimited', 'candidates' => 'unlimited', 'servers' => '6', 'sms' => true],
                ],
            ],
            [
                'key' => 'organization',
                'name' => 'Organization',
                'tiers' => [
                    ['key' => 'basic', 'name' => 'Basic', 'price' => 110000, 'admins' => '12', 'exams' => '50', 'candidates' => '5000', 'servers' => '1', 'sms' => false],
                    ['key' => 'standard', 'name' => 'Standard', 'price' => 220000, 'admins' => '30', 'exams' => '120', 'candidates' => '15000', 'servers' => '3', 'sms' => true],
                    ['key' => 'enterprise', 'name' => 'Enterprise', 'price' => null, 'admins' => 'unlimited', 'exams' => 'unlimited', 'candidates' => 'unlimited', 'servers' => '6', 'sms' => true],
                ],
            ],
        ];

        $plans = [];
        foreach ($entities as $entity) {
            foreach ($entity['tiers'] as $tier) {
                $plans[] = [
                    'name' => $entity['name'].' '.$tier['name'],
                    'slug' => $entity['key'].'-'.$tier['key'],
                    'description' => $this->alignExDescription($entity['name'], $tier['name']),
                    'billing_cycle' => $tier['price'] === null ? BillingCycle::Custom : BillingCycle::Annual,
                    'price' => $tier['price'],
                    'features' => $this->alignExFeatures($entity['name'], $tier),
                ];
            }
        }

        return $plans;
    }

    /** @param array<string, mixed> $tier */
    private function alignExFeatures(string $entityName, array $tier): array
    {
        return [
            ['feature_key' => 'entity_type', 'feature_name' => 'Entity type', 'enabled' => true, 'limit_value' => $entityName],
            ['feature_key' => 'delivery_online', 'feature_name' => 'Online delivery', 'enabled' => true, 'limit_value' => 'enabled'],
            ['feature_key' => 'delivery_offline', 'feature_name' => 'Offline delivery', 'enabled' => true, 'limit_value' => 'enabled'],
            ['feature_key' => 'max_users', 'feature_name' => 'Administrative users', 'enabled' => true, 'limit_value' => (string) $tier['admins']],
            ['feature_key' => 'max_exams_per_month', 'feature_name' => 'Exams per month', 'enabled' => true, 'limit_value' => (string) $tier['exams']],
            ['feature_key' => 'max_candidates', 'feature_name' => 'Candidates', 'enabled' => true, 'limit_value' => (string) $tier['candidates']],
            ['feature_key' => 'max_server_installations', 'feature_name' => 'Offline servers', 'enabled' => true, 'limit_value' => (string) $tier['servers']],
            ['feature_key' => 'sms_enabled', 'feature_name' => 'SMS notifications', 'enabled' => (bool) $tier['sms'], 'limit_value' => null],
        ];
    }

    private function alignExDescription(string $entityName, string $tierName): string
    {
        return sprintf('%s %s plan for controlled online and offline examination delivery.', $entityName, $tierName);
    }
}
