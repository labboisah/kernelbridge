<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PartnerClientRegistered extends Notification implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public function __construct(public readonly string $customerName, public readonly string $url, public readonly bool $forPartner)
    {
        $this->afterCommit();
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->forPartner) {
            return (new MailMessage)->subject('Client registered: '.$this->customerName)
                ->line($this->customerName.' has been registered as your client.')
                ->line('An invitation to set up customer portal access has been sent to the client.')
                ->action('View client subscriptions', $this->url);
        }

        return (new MailMessage)->subject('Your KernelBridge client account')
            ->line($this->customerName.' has been registered with KernelBridge by a partner.')
            ->line('Accept this invitation to set up or link your customer portal account. The invitation expires in seven days.')
            ->action('Accept invitation', $this->url)
            ->line('If you did not expect this invitation, you may ignore this email.');
    }
}
