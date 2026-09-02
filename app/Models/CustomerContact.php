<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class CustomerContact extends Model
{
    protected $fillable = ['uuid', 'name', 'job_title', 'email', 'phone', 'is_primary', 'metadata'];

    protected static function booted(): void
    {
        self::creating(fn (self $contact) => $contact->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'metadata' => 'array'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
