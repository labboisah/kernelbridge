<?php

namespace App\Domain\Finance\Services;

use App\Models\FinancialAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class FinancialAuditLogger
{
    public function record(string $event, Model $subject, User $actor, array $payload): void
    {
        $previous = FinancialAuditLog::query()->lockForUpdate()->latest('id')->first()?->entry_hash;
        $occurredAt = now();
        $canonical = json_encode([$event, $subject::class, $subject->getKey(), $actor->id, $payload, $previous, $occurredAt->toISOString()], JSON_THROW_ON_ERROR);
        FinancialAuditLog::query()->create(['uuid' => (string) Str::uuid(), 'event' => $event, 'subject_type' => $subject::class, 'subject_id' => $subject->getKey(), 'actor_id' => $actor->id, 'payload' => $payload, 'previous_hash' => $previous, 'entry_hash' => hash('sha256', $canonical), 'occurred_at' => $occurredAt]);
    }
}
