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
        return $this->respond($request, $attachment, false);
    }

    public function preview(Request $request, AdminNotificationAttachment $attachment): StreamedResponse
    {
        return $this->respond($request, $attachment, true);
    }

    private function respond(Request $request, AdminNotificationAttachment $attachment, bool $preview): StreamedResponse
    {
        $attachment->load('notification');
        $message = $attachment->notification;
        $allowed = $request->user()->can('notification.manage')
            || $request->user()->notifications()->where('data->context->admin_notification_uuid', $message->uuid)->exists();
        abort_unless($allowed, 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        $allowedPreviewTypes = ['application/pdf', 'text/plain', 'image/jpeg', 'image/png', 'image/webp'];
        abort_if($preview && ! in_array($attachment->mime_type, $allowedPreviewTypes, true), 415);

        return Storage::disk('local')->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type === 'text/plain' ? 'text/plain; charset=UTF-8' : $attachment->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], $preview ? 'inline' : 'attachment');
    }
}
