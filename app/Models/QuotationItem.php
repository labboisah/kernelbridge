<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class QuotationItem extends Model
{
    protected $fillable = ['uuid', 'product_id', 'plan_id', 'product', 'plan', 'description', 'quantity', 'unit_price', 'discount', 'tax', 'total', 'sort_order'];

    protected static function booted(): void
    {
        self::creating(fn (self $i) => $i->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
