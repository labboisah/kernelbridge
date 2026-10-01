<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class SubscriptionPackageOrder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'quoted_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(fn (self $item) => $item->uuid ??= (string) Str::uuid());
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
