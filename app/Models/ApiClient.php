<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class ApiClient extends Model
{
    protected $fillable = ['uuid', 'product_id', 'name', 'is_active', 'rate_limit_per_minute', 'allowed_ips', 'scopes', 'last_used_at'];

    protected static function booted(): void
    {
        self::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'rate_limit_per_minute' => 'integer', 'allowed_ips' => 'array', 'scopes' => 'array', 'last_used_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(ApiClientCredential::class);
    }
}
