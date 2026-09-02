<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class SubscriptionChange extends Model
{
    protected $fillable = ['uuid', 'change_type', 'from_plan_id', 'to_plan_id', 'credit', 'debit', 'net_amount', 'before', 'after', 'reason', 'changed_by', 'effective_at'];

    protected static function booted(): void
    {
        self::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
        self::updating(fn () => throw new \LogicException('Subscription history is immutable.'));
        self::deleting(fn () => throw new \LogicException('Subscription history is immutable.'));
    }

    protected function casts(): array
    {
        return ['credit' => 'decimal:2', 'debit' => 'decimal:2', 'net_amount' => 'decimal:2', 'before' => 'array', 'after' => 'array', 'effective_at' => 'datetime'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
