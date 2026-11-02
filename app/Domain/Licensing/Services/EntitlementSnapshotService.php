<?php

namespace App\Domain\Licensing\Services;

use App\Domain\Subscriptions\Services\PlanEntitlementService;
use App\Models\Subscription;
use Illuminate\Support\Collection;

final class EntitlementSnapshotService
{
    public function __construct(private readonly EntitlementRevisionService $revisions) {}

    /** @return Collection<int, array{key: string, name: string, enabled: bool, limit: mixed}> */
    public function items(Subscription $subscription): Collection
    {
        $subscription = $this->refreshRevision($subscription);
        $now = now();
        $items = $subscription->entitlements()
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $now))
            ->orderBy('feature_key')
            ->get()
            ->map(fn ($e): array => ['key' => $e->feature_key, 'name' => $e->feature_name, 'enabled' => $e->enabled, 'limit' => $e->limit_value])
            ->values();

        // Subscriptions issued before the plan carried this limit have no snapshot row.
        if (! $items->contains(fn (array $item): bool => $item['key'] === 'max_server_installations')) {
            $limit = app(PlanEntitlementService::class)->limit($subscription->plan, 'max_server_installations');

            if (is_numeric($limit) && (int) $limit > 0) {
                $items->push(['key' => 'max_server_installations', 'name' => 'Business Server installations', 'enabled' => true, 'limit' => $limit]);
            }
        }

        return $items;
    }

    public function refreshRevision(Subscription $subscription): Subscription
    {
        return $this->revisions->refresh($subscription);
    }
}
