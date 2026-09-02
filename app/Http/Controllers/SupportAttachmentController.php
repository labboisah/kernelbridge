<?php

namespace App\Http\Controllers;

use App\Models\SupportAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SupportAttachmentController extends Controller
{
    public function __invoke(SupportAttachment $attachment): StreamedResponse
    {
        $attachment->load('message.ticket');
        $this->authorize('view', $attachment->message->ticket);
        abort_if(! $attachment->message->is_customer_visible && ! request()->user()->can('support.note'), 403);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }
}
