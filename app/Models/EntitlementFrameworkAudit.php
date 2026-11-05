<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class EntitlementFrameworkAudit extends Model
{
    protected $fillable = [
        'uuid',
        'product_id',
        'software_license_id',
        'license_platform_account_link_id',
        'source',
        'operation',
        'outcome',
        'catalog_revision',
        'source_revision',
        'idempotency_key',
        'payload_digest',
        'error_code',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        self::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
        self::updating(fn () => throw new \LogicException('Entitlement framework audit records are immutable.'));
        self::deleting(fn () => throw new \LogicException('Entitlement framework audit records are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'catalog_revision' => 'integer',
            'source_revision' => 'integer',
            'occurred_at' => 'datetime',
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
}
