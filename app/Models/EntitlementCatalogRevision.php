<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class EntitlementCatalogRevision extends Model
{
    protected $fillable = [
        'uuid',
        'product_id',
        'schema_version',
        'catalog_revision',
        'catalog',
        'catalog_digest',
        'published_at',
    ];

    protected static function booted(): void
    {
        self::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'catalog_revision' => 'integer',
            'catalog' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
