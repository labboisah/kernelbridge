<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class DemoEvent extends Model
{
    protected $fillable = ['uuid', 'product_id', 'external_event_id', 'event_type', 'page', 'feature', 'cta', 'occurred_at', 'metadata'];

    protected static function booted(): void
    {
        self::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'metadata' => 'array'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(DemoSession::class, 'demo_session_id');
    }
}
