<?php

namespace App\Models;

use App\Enums\ProductMediaType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class ProductMedia extends Model
{
    protected $table = 'product_media';

    protected $fillable = ['uuid', 'type', 'title', 'url', 'alt_text', 'sort_order', 'is_active', 'metadata'];

    protected static function booted(): void
    {
        self::creating(fn (self $media) => $media->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['type' => ProductMediaType::class, 'is_active' => 'boolean', 'metadata' => 'array'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
