<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class AdminNotificationAttachment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path'];

    protected static function booted(): void
    {
        self::creating(fn (self $attachment) => $attachment->uuid ??= (string) Str::uuid());
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(AdminNotification::class, 'admin_notification_id');
    }
}
