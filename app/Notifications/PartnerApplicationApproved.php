<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PartnerApplicationApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $type)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = config("partners.{$this->type}.label", 'Partner');

        return (new MailMessage)
            ->subject('Your KernelBridge partner application has been approved')
            ->greeting("Hello {$notifiable->name},")
            ->line("Your {$label} application has been approved.")
            ->line('Please sign in to continue and update your bank details in the partner portal.')
            ->action('Continue to bank details', route('partner.bank.edit'))
            ->line('Never send your bank details by email.');
    }
}
