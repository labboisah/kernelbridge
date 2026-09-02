<?php

namespace App\Models;

use App\Enums\ProductLinkType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class ProductLink extends Model
{
    protected $fillable = ['uuid', 'type', 'label', 'url', 'sort_order', 'is_active'];

    protected static function booted(): void
    {
        self::creating(fn (self $link) => $link->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['type' => ProductLinkType::class, 'is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
