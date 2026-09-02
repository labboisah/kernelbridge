<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class InvoicePayment extends Model
{
    protected $fillable = ['uuid', 'amount', 'currency', 'reference', 'method', 'status', 'paid_at', 'recorded_by', 'metadata'];

    protected static function booted(): void
    {
        self::creating(fn (self $p) => $p->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'metadata' => 'array'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
