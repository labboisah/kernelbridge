<?php

namespace App\Notifications;

use App\Domain\Notifications\Services\NotificationChannelManager;
use App\Enums\BusinessNotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
        private readonly ?int $deliveryId = null,
        private readonly string $messageFormat = 'plain',
        private readonly array $attachments = [],
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
        $mail = (new MailMessage)->subject($this->subject);
        if (($this->messageFormat ?? 'plain') === 'html') {
            $mail->view(['html' => 'emails.admin-notification', 'text' => 'emails.admin-notification-text'], [
                'body' => $this->message,
            ]);
        } else {
            $mail->line($this->message);
        }
        foreach ($this->attachments ?? [] as $attachment) {
            $disk = Storage::disk('local');
            if (! $disk->exists($attachment['path'])) {
                throw new RuntimeException('Notification attachment is missing.');
            }
            $mail->attach($disk->path($attachment['path']), ['as' => $attachment['name'], 'mime' => $attachment['mime']]);
        }

        return $this->actionUrl ? $mail->action($this->actionLabel ?? 'View details', $this->actionUrl) : $mail;
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => $this->businessType->value, 'subject' => $this->subject, 'message' => $this->message, 'context' => $this->context, 'action_url' => $this->actionUrl];
    }

    public function afterSending(object $notifiable, string $channel, mixed $response): void
    {
        if ($this->deliveryId === null) {
            return;
        }
        if ($channel === 'mail' && $response === null) {
            throw new RuntimeException('The mail channel did not submit the notification.');
        }
        DB::table('admin_notification_deliveries')->where('id', $this->deliveryId)
            ->update(['status' => 'sent', 'updated_at' => now()]);
    }
}
