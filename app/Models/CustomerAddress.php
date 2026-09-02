<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class CustomerAddress extends Model
{
    protected $fillable = ['uuid', 'type', 'label', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'is_primary'];

    protected static function booted(): void
    {
        self::creating(fn (self $address) => $address->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
