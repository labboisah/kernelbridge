<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SubscriptionRenewalNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Subscription $subscription)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Subscription renewal: {$this->subscription->product->name}")->greeting("Hello {$this->subscription->customer->name},")->line("Your {$this->subscription->plan->name} subscription is due for renewal on {$this->subscription->ends_at->toFormattedDateString()}.")->line('Please contact KernelBridge if you need help with renewal or plan changes.');
    }
}
