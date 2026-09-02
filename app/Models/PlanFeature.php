<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class PlanFeature extends Model
{
    protected $fillable = ['uuid', 'feature_key', 'feature_name', 'enabled', 'limit_value', 'sort_order'];

    protected static function booted(): void
    {
        self::creating(fn (self $feature) => $feature->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'sort_order' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
