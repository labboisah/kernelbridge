<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FinancialAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['uuid', 'event', 'subject_type', 'subject_id', 'actor_id', 'payload', 'previous_hash', 'entry_hash', 'occurred_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Financial audit logs are immutable.'));
        self::deleting(fn () => throw new \LogicException('Financial audit logs are immutable.'));
    }
}
