<?php

namespace App\Notifications;

use App\Domain\Notifications\Services\NotificationChannelManager;
use App\Enums\BusinessNotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class BusinessNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly BusinessNotificationType $businessType,
        private readonly string $subject,
        private readonly string $message,
        private readonly array $context = [],
        private readonly ?string $actionUrl = null,
        private readonly ?string $actionLabel = null,
        private readonly ?array $channels = null,
    ) {
        $this->afterCommit();
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return $this->channels ?? app(NotificationChannelManager::class)->channelsFor($notifiable, $this->businessType);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->line($this->message);

        return $this->actionUrl ? $mail->action($this->actionLabel ?? 'View details', $this->actionUrl) : $mail;
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => $this->businessType->value, 'subject' => $this->subject, 'message' => $this->message, 'context' => $this->context, 'action_url' => $this->actionUrl];
    }
}
