<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class PartnerQuotation extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::creating(fn (self $quotation) => $quotation->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expires_at' => 'date',
            'unit_price' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'plan_modules' => 'array',
            'plan_features' => 'array',
        ];
    }
}
