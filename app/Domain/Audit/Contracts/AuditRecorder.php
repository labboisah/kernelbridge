<?php

namespace App\Domain\Audit\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

interface AuditRecorder
{
    /** @param array<string, mixed> $metadata */
    public function record(string $event, ?Authenticatable $actor = null, array $metadata = [], ?Model $entity = null, ?array $before = null, ?array $after = null): void;
}
