<?php

namespace App\Domain\Licensing\Services;

use App\Models\Plan;
use App\Models\Subscription;

final class PackageCatalog
{
    public function modules(array $keys): array
    {
        $modules = [];
        foreach ($keys as $key) {
            $items = config('mediflow.packages.'.$key.'.modules', []);
            $modules = array_merge($modules, in_array('*', $items, true) ? array_keys(config('mediflow.modules')) : $items);
        }

        return array_values(array_unique($modules));
    }

    public function describe(string $key): array
    {
        return [
            'key' => $key, 'name' => config('mediflow.packages.'.$key.'.name', $key),
            'description' => config('mediflow.package_descriptions.'.$key, ''),
            'features' => $this->features($this->modules([$key])),
        ];
    }

    public function features(array $keys): array
    {
        return array_map(fn ($key) => [
            'key' => $key, 'name' => config('mediflow.modules.'.$key, $key),
            'description' => config('mediflow.feature_descriptions.'.$key, ''),
            'required' => in_array($key, ['core', 'access_control'], true), 'enabled' => true,
        ], $keys);
    }

    public function configuration(Subscription $subscription): array
    {
        $keys = data_get($subscription->metadata, 'selected_packages', []);

        return [
            'schema_version' => 1, 'revision' => data_get($subscription->metadata, 'configuration_revision', 1),
            'primary_package' => data_get($subscription->metadata, 'primary_package'),
            'packages' => array_map(function ($key) use ($subscription) {
                $package = $this->describe($key);
                $package['features'] = array_values(array_filter($package['features'],
                    fn ($feature) => in_array($feature['key'], data_get($subscription->metadata, 'licensed_modules', []), true)));

                return $package;
            }, $keys),
            'features' => $this->features(data_get($subscription->metadata, 'licensed_modules', [])),
        ];
    }

    public function plan(Plan $plan): array
    {
        return [
            ...$this->describe($plan->slug), 'uuid' => $plan->uuid,
            'price' => $plan->price, 'setup_fee' => $plan->setup_fee,
            'currency' => $plan->currency, 'billing_cycle' => $plan->billing_cycle->value,
            'modules' => $this->features($this->modules([$plan->slug])),
        ];
    }
}
