<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PartnerClientMilestone extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $subject,
        public readonly string $message,
        public readonly string $actionLabel,
        public readonly string $url,
    ) {
        $this->afterCommit();
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->subject)->line($this->message)
            ->action($this->actionLabel, $this->url);
    }
}
