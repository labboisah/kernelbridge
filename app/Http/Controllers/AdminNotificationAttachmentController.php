<?php

namespace App\Http\Controllers;

use App\Models\AdminNotificationAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminNotificationAttachmentController extends Controller
{
    public function __invoke(Request $request, AdminNotificationAttachment $attachment): StreamedResponse
    {
        $attachment->load('notification');
        $message = $attachment->notification;
        $allowed = $request->user()->hasAnyRole(['admin', 'super_admin'])
            || $request->user()->notifications()->where('data->context->admin_notification_uuid', $message->uuid)->exists();
        abort_unless($allowed, 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
