<?php

namespace App\Domain\Subscriptions\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class PlanManager
{
    public function __construct(private AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function create(Product $product, array $data, User $actor): Plan
    {
        return DB::transaction(function () use ($product, $data, $actor): Plan {
            $plan = $product->subscriptionPlans()->create(Arr::except($data, ['features', 'limits']));
            $plan->products()->syncWithoutDetaching([$product->id]);
            $this->synchronizeEntitlements($plan, $data);
            $this->audit->record('plan.created', $actor, ['plan_uuid' => $plan->uuid, 'product_uuid' => $product->uuid, 'after' => $this->snapshot($plan)]);

            return $plan;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Plan $plan, array $data, User $actor): Plan
    {
        return DB::transaction(function () use ($plan, $data, $actor): Plan {
            $before = $this->snapshot($plan);
            $plan->update(Arr::except($data, ['features', 'limits']));
            $plan->products()->syncWithoutDetaching([$plan->product_id]);
            $this->synchronizeEntitlements($plan, $data);
            $this->audit->record('plan.updated', $actor, ['plan_uuid' => $plan->uuid, 'product_uuid' => $plan->product->uuid, 'before' => $before, 'after' => $this->snapshot($plan)]);

            return $plan;
        });
    }

    public function delete(Plan $plan, User $actor): void
    {
        DB::transaction(function () use ($plan, $actor): void {
            $snapshot = $this->snapshot($plan);
            $uuid = $plan->uuid;
            $productUuid = $plan->product->uuid;
            $plan->delete();
            $this->audit->record('plan.deleted', $actor, ['plan_uuid' => $uuid, 'product_uuid' => $productUuid, 'before' => $snapshot]);
        });
    }

    /** @param array<string, mixed> $data */
    private function synchronizeEntitlements(Plan $plan, array $data): void
    {
        $plan->features()->delete();
        foreach (array_values($data['features'] ?? []) as $index => $feature) {
            $plan->features()->create([...$feature, 'sort_order' => $index]);
        }

        $plan->limits()->delete();
        foreach (array_values($data['limits'] ?? []) as $limit) {
            $plan->limits()->create($limit);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Plan $plan): array
    {
        return $plan->fresh()->load(['features', 'limits'])->toArray();
    }
}
