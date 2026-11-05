<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class EntitlementPlanMapping extends Model
{
    protected $fillable = [
        'uuid',
        'plan_id',
        'entitlement_catalog_revision_id',
        'source_revision',
        'grants',
        'mapping_digest',
        'published_at',
    ];

    protected static function booted(): void
    {
        self::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'source_revision' => 'integer',
            'grants' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function catalogRevision(): BelongsTo
    {
        return $this->belongsTo(EntitlementCatalogRevision::class, 'entitlement_catalog_revision_id');
    }
}
