<?php

namespace App\Domain\Automation\Services;

use App\Domain\Notifications\Services\BusinessNotificationDispatcher;
use App\Domain\Subscriptions\Services\RenewalNoticeService;
use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Enums\BusinessNotificationType;
use App\Models\DemoSession;
use App\Models\Lead;
use App\Models\SoftwareLicense;
use App\Models\Subscription;
use App\Notifications\BusinessNotification;

final readonly class ScheduledAutomationService
{
    public function __construct(private BusinessNotificationDispatcher $notifications) {}

    public function subscriptions(SubscriptionService $lifecycle): array
    {
        $expiring = Subscription::query()->with(['customer.users', 'product'])->whereIn('status', ['active', 'trial'])
            ->whereBetween('ends_at', [now(), now()->addDays(30)])->get();
        foreach ($expiring as $subscription) {
            $this->notifications->once($subscription, BusinessNotificationType::SubscriptionExpiring, $subscription->ends_at->toDateString(), function () use ($subscription): void {
                $this->notifications->toCustomer($subscription->customer, new BusinessNotification(BusinessNotificationType::SubscriptionExpiring, 'Subscription expiring', "Your {$subscription->product->name} subscription expires on {$subscription->ends_at->toFormattedDateString()}.", ['subscription_uuid' => $subscription->uuid]));
            });
        }

        $result = $lifecycle->processLifecycle();
        Subscription::query()->with(['customer.users', 'product'])->where('status', 'expired')->where('updated_at', '>=', now()->subMinutes(10))->each(function (Subscription $subscription): void {
            $this->notifications->once($subscription, BusinessNotificationType::SubscriptionExpired, $subscription->updated_at->toISOString(), function () use ($subscription): void {
                $this->notifications->toCustomer($subscription->customer, new BusinessNotification(BusinessNotificationType::SubscriptionExpired, 'Subscription expired', "Your {$subscription->product->name} subscription has expired.", ['subscription_uuid' => $subscription->uuid]));
            });
        });

        return $result;
    }

    public function renewalReminders(RenewalNoticeService $service): int
    {
        return $service->sendDueNotices();
    }

    public function licenses(): array
    {
        $reminded = 0;
        SoftwareLicense::query()->with(['subscription.customer.users', 'subscription.product'])->where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays(config('notifications.license_reminder_days'))])
            ->each(function (SoftwareLicense $license) use (&$reminded): void {
                $sent = $this->notifications->once($license, BusinessNotificationType::LicenseExpiring, $license->expires_at->toDateString(), function () use ($license): void {
                    $this->notifications->toCustomer($license->subscription->customer, new BusinessNotification(BusinessNotificationType::LicenseExpiring, 'Software license expiring', "Your {$license->subscription->product->name} license expires on {$license->expires_at->toFormattedDateString()}.", ['license_uuid' => $license->uuid]));
                });
                $reminded += (int) $sent;
            });
        $expired = SoftwareLicense::query()->where('status', 'active')->where('expires_at', '<=', now())->update(['status' => 'expired', 'updated_at' => now()]);

        return compact('reminded', 'expired');
    }

    public function cleanupDemoMetadata(): int
    {
        $count = 0;
        DemoSession::query()->whereNull('ended_at')->where('last_activity_at', '<=', now()->subHours(config('notifications.demo_session_retention_hours')))->each(function (DemoSession $session) use (&$count): void {
            $session->update(['ended_at' => $session->last_activity_at, 'metadata' => [...($session->metadata ?? []), 'cleanup' => ['reason' => 'stale_session', 'processed_at' => now()->toISOString()]]]);
            $count++;
        });

        return $count;
    }

    public function staleLeadReminders(): int
    {
        $count = 0;
        Lead::query()->with('assignedUser')->whereNotIn('status', ['won', 'lost'])->whereNotNull('assigned_to')
            ->where(fn ($query) => $query->whereDate('follow_up_date', '<=', today())->orWhere('updated_at', '<=', now()->subDays(config('notifications.stale_lead_days'))))
            ->each(function (Lead $lead) use (&$count): void {
                $occurrence = today()->toDateString();
                $sent = $this->notifications->once($lead, BusinessNotificationType::StaleLeadFollowUp, $occurrence, function () use ($lead): void {
                    $this->notifications->toUser($lead->assignedUser, new BusinessNotification(BusinessNotificationType::StaleLeadFollowUp, 'Lead follow-up due', "{$lead->name} requires sales follow-up.", ['lead_uuid' => $lead->uuid], route('admin.leads.show', $lead), 'Open lead'));
                });
                $count += (int) $sent;
            });

        return $count;
    }
}
