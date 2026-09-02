<?php

namespace App\Domain\Notifications\Services;

use App\Enums\BusinessNotificationType;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\BusinessNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class BusinessNotificationDispatcher
{
    public function toCustomer(Customer $customer, BusinessNotification $notification): void
    {
        $users = $customer->users()->get();
        if ($users->isNotEmpty()) {
            Notification::send($users, $notification);

            return;
        }
        if ($customer->email) {
            Notification::route('mail', $customer->email)->notify($notification);
        }
    }

    public function toUser(?User $user, BusinessNotification $notification): void
    {
        if ($user) {
            $user->notify($notification);
        }
    }

    public function once(Model $subject, BusinessNotificationType $type, string $occurrence, callable $send, array $metadata = []): bool
    {
        $key = hash('sha256', implode('|', [$type->value, $subject->getMorphClass(), $subject->getKey(), $occurrence]));

        return DB::transaction(function () use ($key, $type, $subject, $send, $metadata): bool {
            $inserted = DB::table('automation_notifications')->insertOrIgnore([
                'key' => $key, 'type' => $type->value, 'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(), 'processed_at' => now(),
                'metadata' => $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
            ]);
            if ($inserted === 0) {
                return false;
            }
            $send();

            return true;
        });
    }
}
