<?php

namespace App\Domain\Subscriptions\Services;

use App\Models\Subscription;
use App\Notifications\SubscriptionRenewalNotice;
use Illuminate\Support\Facades\Notification;

final class RenewalNoticeService
{
    public function sendDueNotices(int $daysAhead = 30): int
    {
        $sent = 0;
        Subscription::query()->with(['customer', 'product', 'plan'])->where('status', 'active')->where('auto_renew', true)->whereNull('renewal_notice_sent_at')->whereBetween('ends_at', [now(), now()->addDays($daysAhead)])->each(function (Subscription $subscription) use (&$sent): void {
            if ($subscription->customer->email) {
                Notification::route('mail', $subscription->customer->email)->notify(new SubscriptionRenewalNotice($subscription));
                $subscription->update(['renewal_notice_sent_at' => now()]);
                $sent++;
            }
        });

        return $sent;
    }
}
