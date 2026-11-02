<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class LicensePlatformAccountLink extends Model
{
    protected $fillable = [
        'uuid',
        'software_license_id',
        'product_id',
        'business_uuid',
        'status',
        'requested_by_api_client_id',
        'approved_by_user_id',
        'approved_at',
        'rejected_at',
        'revoked_at',
    ];

    protected static function booted(): void
    {
        self::creating(fn (self $link) => $link->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(SoftwareLicense::class, 'software_license_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function requestedByApiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'requested_by_api_client_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
