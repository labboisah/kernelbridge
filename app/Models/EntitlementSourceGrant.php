<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class EntitlementSourceGrant extends Model
{
    protected $fillable = [
        'uuid',
        'product_id',
        'software_license_id',
        'license_platform_account_link_id',
        'entitlement_catalog_revision_id',
        'source',
        'source_revision',
        'idempotency_key',
        'grants',
        'payload_digest',
        'accepted_at',
    ];

    protected static function booted(): void
    {
        self::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
        self::saving(function (self $model): void {
            if ($model->source !== 'product_platform') {
                throw new \InvalidArgumentException('Customer source grants must come from the product platform.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'source_revision' => 'integer',
            'grants' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(SoftwareLicense::class, 'software_license_id');
    }

    public function platformAccountLink(): BelongsTo
    {
        return $this->belongsTo(LicensePlatformAccountLink::class, 'license_platform_account_link_id');
    }

    public function catalogRevision(): BelongsTo
    {
        return $this->belongsTo(EntitlementCatalogRevision::class, 'entitlement_catalog_revision_id');
    }
}
