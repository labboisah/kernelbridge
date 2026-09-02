<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class LeadActivity extends Model
{
    protected $fillable = ['uuid', 'actor_id', 'type', 'description', 'metadata'];

    protected static function booted(): void
    {
        self::creating(fn (self $activity) => $activity->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
