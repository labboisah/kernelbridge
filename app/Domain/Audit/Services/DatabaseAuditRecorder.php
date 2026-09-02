<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Contracts\AuditRecorder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class DatabaseAuditRecorder implements AuditRecorder
{
    public function record(string $event, ?Authenticatable $actor = null, array $metadata = [], ?Model $entity = null, ?array $before = null, ?array $after = null): void
    {
        $before ??= is_array($metadata['before'] ?? null) ? $metadata['before'] : null;
        $after ??= is_array($metadata['after'] ?? null) ? $metadata['after'] : null;
        DB::table('audit_logs')->insert([
            'id' => (string) str()->ulid(),
            'event' => $event,
            'action' => $event,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getAuthIdentifier(),
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity ? (string) ($entity->getAttribute('uuid') ?? $entity->getKey()) : null,
            'before' => $before ? json_encode($this->redact($before), JSON_THROW_ON_ERROR) : null,
            'after' => $after ? json_encode($this->redact($after), JSON_THROW_ON_ERROR) : null,
            'metadata' => json_encode($this->redact($metadata), JSON_THROW_ON_ERROR),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/password|secret|token|recovery|key_hash|proof/i', (string) $key)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
