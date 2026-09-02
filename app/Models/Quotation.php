<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class Quotation extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'quotation_number', 'customer_id', 'issue_date', 'expiry_date', 'subtotal', 'discount', 'tax', 'total', 'currency', 'status', 'notes', 'terms', 'created_by', 'sent_at', 'accepted_at', 'rejected_at'];

    protected static function booted(): void
    {
        self::creating(fn (self $q) => $q->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['status' => QuotationStatus::class, 'issue_date' => 'date', 'expiry_date' => 'date', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'sent_at' => 'datetime', 'accepted_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order');
    }

    public function invoice(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
