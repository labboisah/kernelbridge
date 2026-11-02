<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class LicenseActivationCode extends Model
{
    protected $fillable = [
        'uuid',
        'software_license_id',
        'product_id',
        'license_platform_account_link_id',
        'bound_installation_uuid',
        'device_name',
        'issued_by',
        'code_lookup',
        'code_lookup_key_id',
        'code_hash',
        'status',
        'expires_at',
        'redeemed_at',
        'redeemed_installation_uuid',
        'revoked_at',
        'replacement_of_id',
        'replaced_by_id',
    ];

    protected $hidden = ['code_lookup', 'code_hash'];

    protected static function booted(): void
    {
        self::creating(fn (self $code) => $code->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
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

    public function platformAccountLink(): BelongsTo
    {
        return $this->belongsTo(LicensePlatformAccountLink::class, 'license_platform_account_link_id');
    }
}
