<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class LicenseInstallation extends Model
{
    protected $fillable = [
        'uuid',
        'software_license_id',
        'product_id',
        'license_platform_account_link_id',
        'installation_uuid',
        'business_uuid',
        'credential_hash',
        'credential_key_id',
        'credential_encrypted',
        'device_name',
        'runtime_type',
        'status',
        'activated_at',
        'last_verified_at',
        'deactivated_at',
        'revoked_at',
        'expires_at',
    ];

    protected $hidden = ['credential_hash', 'credential_encrypted'];

    protected static function booted(): void
    {
        self::creating(fn (self $installation) => $installation->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(SoftwareLicense::class, 'software_license_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function platformAccountLink(): BelongsTo
    {
        return $this->belongsTo(LicensePlatformAccountLink::class, 'license_platform_account_link_id');
    }
}
