<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Seeder;

final class MediFlowPackageSeeder extends Seeder
{
    public function run(): void
    {
        $product = Product::query()->where('code', 'MEDIFLOW')->firstOrFail();
        foreach (config('mediflow.packages') as $key => $package) {
            // Preserve configured prices and administrator changes on subsequent runs.
            $plan = Plan::query()->firstOrCreate(['product_id' => $product->id, 'slug' => $key], [
                'name' => $package['name'], 'description' => config('mediflow.package_descriptions.'.$key),
                'price' => null, 'currency' => 'NGN', 'billing_cycle' => 'annual',
                'setup_fee' => 0, 'trial_days' => 0, 'grace_period_days' => 0,
                'is_active' => true, 'is_public' => true, 'metadata' => ['mediflow_package' => $key],
            ]);
            $plan->products()->syncWithoutDetaching([$product->id]);
            if (! $plan->wasRecentlyCreated) {
                continue;
            }
            $modules = in_array('*', $package['modules'], true) ? array_keys(config('mediflow.modules')) : $package['modules'];
            foreach ($modules as $module) {
                $plan->features()->firstOrCreate(['feature_key' => $module], [
                    'feature_name' => config('mediflow.modules.'.$module), 'description' => config('mediflow.feature_descriptions.'.$module), 'enabled' => true,
                ]);
            }
        }
    }
}
