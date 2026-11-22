<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class NotificationTemplate extends Model
{
    protected $fillable = ['name', 'subject', 'message', 'created_by'];

    protected static function booted(): void
    {
        self::creating(fn (self $template) => $template->uuid ??= (string) Str::uuid());
    }
}
