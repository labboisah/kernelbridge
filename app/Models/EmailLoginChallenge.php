<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLoginChallenge extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'code_hash',
        'session_hash',
        'expires_at',
        'consumed_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
