<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class LicenseActivation extends Model
{
    protected $fillable = ['uuid', 'device_fingerprint', 'device_name', 'status', 'activated_at', 'last_verified_at', 'deactivated_at', 'ip_address', 'metadata'];

    protected static function booted(): void
    {
        self::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'last_verified_at' => 'datetime', 'deactivated_at' => 'datetime', 'metadata' => 'array'];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(SoftwareLicense::class, 'software_license_id');
    }
}
