<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class Payment extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'payment_number', 'customer_id', 'amount', 'currency', 'payment_method', 'transaction_reference', 'payment_date', 'status', 'proof_of_payment', 'verified_by', 'verified_at', 'notes', 'provider', 'provider_reference', 'metadata', 'recorded_by', 'parent_payment_id'];

    protected static function booted(): void
    {
        self::creating(fn (self $payment) => $payment->uuid ??= (string) Str::uuid());
        self::deleting(fn () => throw new \LogicException('Financial records cannot be hard-deleted; use a reversal or refund.'));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'payment_method' => PaymentMethod::class, 'status' => PaymentStatus::class, 'payment_date' => 'date', 'verified_at' => 'datetime', 'metadata' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function parentPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_payment_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
