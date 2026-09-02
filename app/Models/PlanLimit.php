<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class PlanLimit extends Model
{
    protected $fillable = ['uuid', 'limit_key', 'limit_name', 'limit_value', 'metadata'];

    protected static function booted(): void
    {
        self::creating(fn (self $limit) => $limit->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
