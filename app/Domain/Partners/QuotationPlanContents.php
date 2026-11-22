<?php

namespace App\Domain\Partners;

use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Support\Str;

final class QuotationPlanContents
{
    public function forPlan(Plan $plan): array
    {
        $plan->loadMissing(['product', 'features', 'limits']);
        $modules = [];
        $features = [];
        $consumed = [];

        if ($plan->product->code === 'EDUNEXA') {
            foreach (config('edunexa.modules', []) as $module) {
                $parentKey = 'module_'.Str::snake($module['key']);
                $prefix = Str::snake($module['key'].'_');
                $parent = $plan->features->firstWhere('feature_key', $parentKey);
                $children = $plan->features->filter(fn (PlanFeature $feature) => str_starts_with($feature->feature_key, $prefix));
                $consumed[] = $parentKey;
                foreach ($children as $child) {
                    $consumed[] = $child->feature_key;
                }
                if ($parent?->enabled) {
                    $modules[] = [
                        ...$this->feature($parent),
                        'features' => $children->filter(fn (PlanFeature $feature) => $feature->enabled)
                            ->map(fn (PlanFeature $feature) => $this->feature($feature))->values()->all(),
                    ];
                }
            }
        }

        foreach ($plan->features as $feature) {
            if (! $feature->enabled || in_array($feature->feature_key, $consumed, true)) {
                continue;
            }
            $consumed[] = $feature->feature_key;
            if ($plan->product->code === 'MEDIFLOW' || str_starts_with($feature->feature_key, 'module_')) {
                $modules[] = [...$this->feature($feature), 'features' => []];
            } else {
                $features[] = $this->feature($feature);
            }
        }
        foreach ($plan->limits as $limit) {
            if (! in_array($limit->limit_key, $consumed, true)) {
                $features[] = ['name' => $limit->limit_name, 'description' => null, 'limit' => $limit->limit_value];
            }
        }

        return ['modules' => $modules, 'features' => $features];
    }

    private function feature(PlanFeature $feature): array
    {
        return ['name' => $feature->feature_name, 'description' => $feature->description, 'limit' => $feature->limit_value];
    }
}
