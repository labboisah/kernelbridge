<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class AdminNotification extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::creating(fn (self $message) => $message->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['channels' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
