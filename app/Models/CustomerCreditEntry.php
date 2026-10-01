<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CustomerCreditEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        self::creating(fn (self $item) => $item->uuid ??= (string) Str::uuid());
        self::updating(fn () => throw new \LogicException('Credit entries are immutable.'));
        self::deleting(fn () => throw new \LogicException('Credit entries are immutable.'));
    }
}
