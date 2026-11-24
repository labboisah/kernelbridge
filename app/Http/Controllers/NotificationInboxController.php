<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\Services\NotificationMessageFormatter;
use App\Models\AdminNotification;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class NotificationInboxController extends Controller
{
    public function index(Request $request, NotificationMessageFormatter $formatter)
    {
        $notifications = $request->user()->notifications()->latest()->paginate(5);
        $ids = $notifications->getCollection()->pluck('data.context.admin_notification_uuid')->filter()->unique();
        $messages = AdminNotification::whereIn('uuid', $ids)->with('attachments')->get()->keyBy('uuid');
        $notifications->getCollection()->each(function ($notification) use ($messages, $formatter): void {
            $data = $notification->data;
            $message = $messages->get(data_get($data, 'context.admin_notification_uuid'));
            if ($message && data_get($data, 'context.message_format', $message->message_format) === 'html') {
                $notification->setAttribute('formatted_message', $formatter->sanitize($data['message'], 'html'));
            }
            $notification->setAttribute('attachments', $message?->attachments ?? []);
        });

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
        ]);
    }

    public function read(Request $request, string $notification)
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return back();
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
