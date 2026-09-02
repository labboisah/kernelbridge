<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class SubscriptionEntitlement extends Model
{
    protected $fillable = ['uuid', 'feature_key', 'feature_name', 'enabled', 'limit_value', 'source_plan_id', 'effective_from', 'effective_until'];

    protected static function booted(): void
    {
        self::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'effective_from' => 'datetime', 'effective_until' => 'datetime'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function sourcePlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'source_plan_id');
    }
}
