<?php

namespace App\Domain\Audit\Observers;

use App\Domain\Audit\Contracts\AuditRecorder;
use Illuminate\Database\Eloquent\Model;

final class SecurityAuditObserver
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function created(Model $model): void
    {
        $this->audit->record($this->action($model, 'created'), auth()->user(), entity: $model, after: $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $before = array_intersect_key($model->getRawOriginal(), $model->getChanges());
        $after = array_intersect_key($model->getAttributes(), $model->getChanges());
        $this->audit->record($this->action($model, 'updated'), auth()->user(), entity: $model, before: $before, after: $after);
    }

    public function deleted(Model $model): void
    {
        $this->audit->record($this->action($model, 'deleted'), auth()->user(), entity: $model, before: $model->getRawOriginal());
    }

    private function action(Model $model, string $verb): string
    {
        return str(class_basename($model))->snake()->append('.'.$verb)->toString();
    }
}
