<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class LicenseBusinessClaim extends Model
{
    protected $fillable = ['uuid', 'software_license_id', 'product_id', 'business_uuid'];

    protected static function booted(): void
    {
        self::creating(fn (self $claim) => $claim->uuid ??= (string) Str::uuid());
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(SoftwareLicense::class, 'software_license_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}