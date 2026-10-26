<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

final class SoftwareLicense extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'subscription_id', 'customer_id', 'product_id', 'key_lookup', 'key_hash', 'status', 'max_activations', 'expires_at', 'metadata'];

    protected $hidden = ['key_lookup', 'key_hash'];

    protected static function booted(): void
    {
        self::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'metadata' => 'array'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class);
    }

    public function businessClaim(): HasOne
    {
        return $this->hasOne(LicenseBusinessClaim::class);
    }
}
