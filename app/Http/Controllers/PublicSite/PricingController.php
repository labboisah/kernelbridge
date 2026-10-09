<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Http\Resources\PublicSite\ProductResource;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class PricingController extends Controller
{
    public function show(Product $product): Response
    {
        abort_unless($product->status->value === 'active', 404);

        $plans = $product->subscriptionPlans()->with(['product', 'features', 'limits'])->publiclyAvailable()->get()
            ->map(function (Plan $plan) use ($product): array {
                $data = (new PlanResource($plan))->resolve();
                if ($product->code !== 'EDUNEXA') return $data;

                $planFeatures = $plan->features->keyBy('feature_key');
                $moduleKeys = collect(config('edunexa.modules', []))->flatMap(fn (array $module) => collect($module['features'])->map(fn (array $feature) => Str::snake($module['key'].'_'.$feature['name'])))->all();
                $groups = collect(config('edunexa.modules', []))->filter(function (array $module) use ($planFeatures): bool {
                    $key = 'module_'.Str::snake($module['key']);
                    return (bool) $planFeatures->get($key)?->enabled;
                })->map(function (array $module) use ($planFeatures): array {
                    $features = collect($module['features'])->map(function (array $feature) use ($module, $planFeatures): array {
                        $key = Str::snake($module['key'].'_'.$feature['name']);
                        $moduleKeys[] = $key;
                        $saved = $planFeatures->get($key);
                        return ['key' => $key, 'name' => $saved?->feature_name ?? $feature['name'],
                            'description' => $saved?->description ?? $feature['description'],
                            'enabled' => (bool) $saved?->enabled, 'ready' => $feature['status'] === 'ready'];
                    })->values();
                    return ['code' => $module['key'], 'name' => $module['name'], 'description' => $module['description'], 'features' => $features];
                })->values();
                $parentKeys = collect(config('edunexa.modules', []))->map(fn (array $module) => 'module_'.Str::snake($module['key']))->all();
                $data['module_groups'] = $groups;
                $data['other_features'] = $plan->features->reject(fn ($feature) => in_array($feature->feature_key, array_merge($parentKeys, $moduleKeys), true))->values();

                return $data;
            });

        return Inertia::render('Public/Products/Pricing', [
            'product' => (new ProductResource($product))->resolve(),
            'plans' => $plans,
        ]);
    }
}