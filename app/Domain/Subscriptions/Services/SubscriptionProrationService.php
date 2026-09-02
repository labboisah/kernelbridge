<?php

namespace App\Domain\Subscriptions\Services;

use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonInterface;

final class SubscriptionProrationService
{
    public function calculate(Subscription $subscription, Plan $newPlan, CarbonInterface $effectiveAt): array
    {
        if (! $subscription->starts_at || ! $subscription->ends_at || $effectiveAt->gte($subscription->ends_at)) {
            return ['credit' => '0.00', 'debit' => '0.00', 'net_amount' => '0.00'];
        }
        $totalSeconds = max(1, $subscription->starts_at->diffInSeconds($subscription->ends_at));
        $remainingSeconds = max(0, $effectiveAt->diffInSeconds($subscription->ends_at));
        $ratio = $remainingSeconds / $totalSeconds;
        $credit = round((float) $subscription->price * $ratio, 2);
        $debit = round((float) $newPlan->price * $ratio, 2);

        return ['credit' => number_format($credit, 2, '.', ''), 'debit' => number_format($debit, 2, '.', ''), 'net_amount' => number_format($debit - $credit, 2, '.', '')];
    }
}
