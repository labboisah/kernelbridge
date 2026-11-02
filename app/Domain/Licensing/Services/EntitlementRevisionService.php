<?php

namespace App\Domain\Licensing\Services;

use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

final class EntitlementRevisionService
{
    public function refresh(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription): Subscription {
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $now = now();
            $checkedAt = $locked->entitlement_revision_checked_at ?? $locked->created_at;

            $crossedBoundaries = DB::table('subscription_entitlements')
                ->where('subscription_id', $locked->id)
                ->where(function ($query) use ($checkedAt, $now): void {
                    $query->whereBetween('effective_from', [$checkedAt, $now])
                        ->orWhereBetween('effective_until', [$checkedAt, $now]);
                })
                ->count();

            if ($crossedBoundaries > 0) {
                DB::table('subscriptions')->where('id', $locked->id)->increment(
                    'entitlement_revision',
                    $crossedBoundaries,
                    ['entitlement_revision_checked_at' => $now],
                );
                $locked->refresh();
            } elseif (! $locked->entitlement_revision_checked_at) {
                $locked->forceFill(['entitlement_revision_checked_at' => $now])->saveQuietly();
            }

            return $locked;
        });
    }
}
