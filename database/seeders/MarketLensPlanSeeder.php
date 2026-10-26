<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Seeder;

final class MarketLensPlanSeeder extends Seeder
{
    /** @var array<string, array{name: string, description: string, modes: array<string, bool>}> */
    private const PLANS = [
        'marketlens-sales' => [
            'name' => 'MarketLens Sales',
            'description' => 'Sales and stock workflows for branches licensed for sales operations.',
            'modes' => ['mode_sales' => true, 'mode_production' => false, 'mode_services' => false],
        ],
        'marketlens-production' => [
            'name' => 'MarketLens Production',
            'description' => 'Production workflows for branches licensed for production operations.',
            'modes' => ['mode_sales' => false, 'mode_production' => true, 'mode_services' => false],
        ],
        'marketlens-services' => [
            'name' => 'MarketLens Services',
            'description' => 'Service job and revenue workflows for branches licensed for services operations.',
            'modes' => ['mode_sales' => false, 'mode_production' => false, 'mode_services' => true],
        ],
        'marketlens-complete' => [
            'name' => 'MarketLens Complete',
            'description' => 'Sales, production, and services workflows for businesses using multiple operating modes.',
            'modes' => ['mode_sales' => true, 'mode_production' => true, 'mode_services' => true],
        ],
    ];

    /** @var array<string, string> */
    private const FEATURE_NAMES = [
        'mode_sales' => 'Sales operating mode',
        'mode_production' => 'Production operating mode',
        'mode_services' => 'Services operating mode',
        'module_shareholders' => 'Shareholder management',
        'max_server_installations' => 'Business Server installations',
    ];

    /** @var array<string, string> */
    private const FEATURE_DESCRIPTIONS = [
        'mode_sales' => 'Allows licensed branches to configure and use sales operations.',
        'mode_production' => 'Allows licensed branches to configure and use production operations.',
        'mode_services' => 'Allows licensed branches to configure and use service operations.',
        'module_shareholders' => 'Enables shareholder and ownership management alongside any licensed operating mode.',
        'max_server_installations' => 'Maximum active Business Server installations covered by this plan. Administrators can edit this limit.',
    ];

    public function run(): void
    {
        $product = Product::query()->where('code', 'MARKETLENS')->firstOrFail();

        $product->subscriptionPlans()
            ->whereIn('slug', ['basic', 'professional', 'enterprise'])
            ->where('is_public', true)
            ->update(['is_public' => false]);

        foreach (self::PLANS as $sortOrder => $definition) {
            $plan = $product->subscriptionPlans()->firstOrCreate(
                ['slug' => $sortOrder],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'billing_cycle' => 'custom',
                    'price' => null,
                    'currency' => 'NGN',
                    'setup_fee' => 0,
                    'trial_days' => 0,
                    'grace_period_days' => 0,
                    'is_active' => true,
                    'is_public' => true,
                    'sort_order' => array_search($sortOrder, array_keys(self::PLANS), true) + 1,
                    'metadata' => [
                        'catalogue_source' => 'marketlens_plan_seeder',
                        'commercial_approval_required' => true,
                        'catalogue_visibility_migrated' => true,
                    ],
                ],
            );

            $this->publishUnmodifiedDraftFromPreviousSeeder($plan);
            $plan->products()->syncWithoutDetaching([$product->id]);
            $this->seedFeatures($plan, $definition['modes']);
        }
    }

    /** @param array<string, bool> $modes */
    private function seedFeatures(Plan $plan, array $modes): void
    {
        $features = [...$modes, 'module_shareholders' => true];

        foreach ($features as $key => $enabled) {
            $plan->features()->firstOrCreate(
                ['feature_key' => $key],
                [
                    'feature_name' => self::FEATURE_NAMES[$key],
                    'description' => self::FEATURE_DESCRIPTIONS[$key],
                    'enabled' => $enabled,
                    'limit_value' => null,
                    'sort_order' => array_search($key, array_keys(self::FEATURE_NAMES), true),
                ],
            );
        }

        $plan->features()->firstOrCreate(
            ['feature_key' => 'max_server_installations'],
            [
                'feature_name' => self::FEATURE_NAMES['max_server_installations'],
                'description' => self::FEATURE_DESCRIPTIONS['max_server_installations'],
                'enabled' => true,
                'limit_value' => '1',
                'sort_order' => array_search('max_server_installations', array_keys(self::FEATURE_NAMES), true),
            ],
        );
    }

    private function publishUnmodifiedDraftFromPreviousSeeder(Plan $plan): void
    {
        $metadata = $plan->metadata ?? [];
        if (
            ($metadata['catalogue_source'] ?? null) !== 'marketlens_plan_seeder'
            || ($metadata['commercial_approval_required'] ?? false) !== true
            || ($metadata['catalogue_visibility_migrated'] ?? false) === true
            || $plan->price !== null
            || $plan->is_active
            || $plan->is_public
        ) {
            return;
        }

        $plan->forceFill([
            'is_active' => true,
            'is_public' => true,
            'metadata' => [...$metadata, 'catalogue_visibility_migrated' => true],
        ])->save();
    }
}