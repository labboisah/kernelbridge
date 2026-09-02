<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class ProductFeature extends Model
{
    protected $fillable = ['uuid', 'name', 'description', 'icon', 'sort_order', 'is_active'];

    protected static function booted(): void
    {
        self::creating(fn (self $feature) => $feature->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
