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

        // Backfill plan limits for subscriptions created before the limit was added.
        foreach ([
            'max_server_installations' => 'Business Server installations',
            'max_client_app_devices' => 'Business App devices',
        ] as $key => $name) {
            if (! $items->contains(fn (array $item): bool => $item['key'] === $key)) {
                $limit = app(PlanEntitlementService::class)->limit($subscription->plan, $key);

                if (is_numeric($limit) && (int) $limit >= 0) {
                    $items->push(['key' => $key, 'name' => $name, 'enabled' => true, 'limit' => $limit]);
                }
            }
        }

        return $items;
    }

    public function refreshRevision(Subscription $subscription): Subscription
    {
        return $this->revisions->refresh($subscription);
    }
}
