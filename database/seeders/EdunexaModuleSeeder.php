<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class EdunexaModuleSeeder extends Seeder
{
    public function run(): void
    {
        $product = Product::query()->where('code', 'EDUNEXA')->first();
        if (! $product) return;

        $tierModules = [
            'basic' => ['core-school-operations', 'admission-and-enrollment'],
            'professional' => ['core-school-operations', 'admission-and-enrollment', 'academic-records-and-results', 'finance-and-fees'],
            'enterprise' => array_column(config('edunexa.modules', []), 'key'),
        ];

        foreach ($tierModules as $slug => $included) {
            $plan = Plan::query()->where('product_id', $product->id)->where('slug', $slug)->first();
            if (! $plan) continue;

            foreach (config('edunexa.modules', []) as $moduleIndex => $module) {
                $moduleEnabled = in_array($module['key'], $included, true) && $module['status'] === 'ready';
                $moduleKey = 'module_'.Str::snake($module['key']);
                $plan->features()->updateOrCreate(['feature_key' => $moduleKey], [
                    'feature_name' => $module['name'], 'description' => $module['description'],
                    'enabled' => $moduleEnabled, 'limit_value' => null, 'sort_order' => 100 + ($moduleIndex * 20),
                ]);

                foreach ($module['features'] as $featureIndex => $feature) {
                    $featureKey = Str::snake($module['key'].'_'.$feature['name']);
                    $plan->features()->updateOrCreate(['feature_key' => $featureKey], [
                        'feature_name' => $feature['name'], 'description' => $feature['description'],
                        'enabled' => $moduleEnabled && $feature['status'] === 'ready', 'limit_value' => null,
                        'sort_order' => 101 + ($moduleIndex * 20) + $featureIndex,
                    ]);
                }
            }
        }

        // Keep the earlier standalone module records for history, but remove them from checkout.
        Plan::query()->where('product_id', $product->id)->where('slug', 'like', 'edunexa-module-%')
            ->update(['is_active' => false, 'is_public' => false]);
    }
}