<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class LicenseInstallationGrant extends Model
{
    protected $fillable = [
        'uuid',
        'software_license_id',
        'product_id',
        'issued_by_api_client_id',
        'business_uuid',
        'branch_uuid',
        'device_uuid',
        'installation_uuid',
        'credential_hash',
        'credential_encrypted',
        'status',
        'expires_at',
        'last_verified_at',
        'revoked_at',
    ];

    protected $hidden = ['credential_hash', 'credential_encrypted'];

    protected static function booted(): void
    {
        self::creating(fn (self $grant) => $grant->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'revoked_at' => 'datetime',
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

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'issued_by_api_client_id');
    }
}