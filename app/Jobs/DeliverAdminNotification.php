<?php

namespace App\Jobs;

use App\Enums\BusinessNotificationType;
use App\Models\AdminNotification;
use App\Models\User;
use App\Notifications\BusinessNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

final class DeliverAdminNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $deliveryId,
        public readonly ?int $userId,
        public readonly ?string $email,
        public readonly string $channel,
        private readonly string $subject,
        private readonly string $message,
        private readonly string $notificationUuid,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function handle(): void
    {
        $delivery = DB::table('admin_notification_deliveries')->where('id', $this->deliveryId)->first();
        if (! $delivery) {
            throw new RuntimeException('Notification delivery record is missing.');
        }
        if ($delivery->status === 'sent') {
            return;
        }
        DB::table('admin_notification_deliveries')->where('id', $this->deliveryId)->update(['status' => 'processing', 'updated_at' => now()]);
        try {
            $message = AdminNotification::where('uuid', $this->notificationUuid)->with('attachments')->firstOrFail();
            $notifiable = $this->userId !== null
                ? User::findOrFail($this->userId)
                : Notification::route('mail', $this->email);
            Notification::sendNow($notifiable, new BusinessNotification(
                BusinessNotificationType::AdminMessage, $this->subject, $this->message,
                ['admin_notification_uuid' => $this->notificationUuid, 'message_format' => $message->message_format],
                channels: [$this->channel], deliveryId: $this->deliveryId,
                messageFormat: $message->message_format,
                attachments: $message->attachments->map(fn ($attachment) => [
                    'path' => $attachment->path, 'name' => $attachment->original_name, 'mime' => $attachment->mime_type,
                ])->all(),
            ), [$this->channel]);
            if (DB::table('admin_notification_deliveries')->where('id', $this->deliveryId)->value('status') !== 'sent') {
                throw new RuntimeException('The notification was not sent by its channel.');
            }
        } catch (Throwable $exception) {
            DB::table('admin_notification_deliveries')->where('id', $this->deliveryId)->where('status', '!=', 'sent')
                ->update(['status' => 'pending', 'updated_at' => now()]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('admin_notification_deliveries')->where('id', $this->deliveryId)
            ->where('status', '!=', 'sent')->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
