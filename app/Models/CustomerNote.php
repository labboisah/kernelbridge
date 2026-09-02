<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class CustomerNote extends Model
{
    protected $fillable = ['uuid', 'author_id', 'body', 'is_private'];

    protected static function booted(): void
    {
        self::creating(fn (self $note) => $note->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['is_private' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
