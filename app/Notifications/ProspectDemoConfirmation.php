<?php

namespace App\Notifications;

use App\Models\DemoRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ProspectDemoConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly DemoRequest $demoRequest)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We received your KernelBridge demo request')
            ->greeting("Hello {$this->demoRequest->name},")
            ->line('Thank you for requesting a KernelBridge product demonstration.')
            ->line('Our sales team will review your requirements and contact you to confirm the session.')
            ->line('Reference: '.$this->demoRequest->public_id)
            ->action('Explore KernelBridge', route('products.index'));
    }
}
