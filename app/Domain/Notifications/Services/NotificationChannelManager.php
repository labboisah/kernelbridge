<?php

namespace App\Domain\Notifications\Services;

use App\Enums\BusinessNotificationType;
use Illuminate\Notifications\AnonymousNotifiable;

final class NotificationChannelManager
{
    public function channelsFor(object $notifiable, BusinessNotificationType $type): array
    {
        $channels = $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['database', 'mail'];

        // Future SMS and WhatsApp drivers are added here based on recipient preferences and event type.
        return array_values(array_filter($channels, fn (string $channel) => config("notifications.channels.$channel", true)));
    }
}
