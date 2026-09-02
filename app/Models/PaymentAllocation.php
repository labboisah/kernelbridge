<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class PaymentAllocation extends Model
{
    protected $fillable = ['uuid', 'payment_id', 'invoice_id', 'amount', 'allocated_by', 'allocated_at'];

    protected static function booted(): void
    {
        self::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
        self::deleting(fn () => throw new \LogicException('Financial allocations are immutable.'));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'allocated_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
