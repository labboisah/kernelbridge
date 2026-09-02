<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class DemoAccessToken extends Model
{
    protected $fillable = ['uuid', 'token_lookup', 'token_hash', 'label', 'max_uses', 'uses', 'expires_at', 'last_used_at', 'revoked_at'];

    protected $hidden = ['token_lookup', 'token_hash'];

    protected static function booted(): void
    {
        self::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DemoProfile::class, 'demo_profile_id');
    }
}
