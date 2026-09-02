<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class DemoSession extends Model
{
    protected $fillable = ['uuid', 'product_id', 'demo_profile_id', 'demo_access_token_id', 'anonymous_identifier_hash', 'organization_identifier', 'started_at', 'last_activity_at', 'ended_at', 'entry_url', 'referrer', 'metadata'];

    protected static function booted(): void
    {
        self::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'last_activity_at' => 'datetime', 'ended_at' => 'datetime', 'metadata' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DemoProfile::class, 'demo_profile_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DemoEvent::class);
    }
}
