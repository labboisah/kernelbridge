<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class Invoice extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'invoice_number', 'quotation_id', 'customer_id', 'issue_date', 'due_date', 'subtotal', 'discount', 'tax', 'total', 'amount_paid', 'balance', 'currency', 'status', 'notes', 'terms', 'payment_reference', 'created_by', 'issued_at', 'paid_at', 'cancelled_at'];

    protected static function booted(): void
    {
        self::creating(fn (self $i) => $i->uuid ??= (string) Str::uuid());
        self::deleting(fn () => throw new \LogicException('Invoices cannot be hard-deleted; cancel the invoice instead.'));
    }

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'due_date' => 'date', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'amount_paid' => 'decimal:2', 'balance' => 'decimal:2', 'status' => InvoiceStatus::class, 'issued_at' => 'datetime', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->latest('paid_at');
    }
}
