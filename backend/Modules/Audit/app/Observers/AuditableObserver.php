<?php

namespace Modules\Audit\Observers;

use Illuminate\Database\Eloquent\Model;
use Modules\Audit\Services\AuditLogger;

class AuditableObserver
{
    public function __construct(private readonly AuditLogger $logger) {}

    public function created(Model $model): void
    {
        $this->logger->record(
            action: 'created',
            model: $model,
            before: null,
            after: $this->payload($model),
        );
    }

    public function updated(Model $model): void
    {
        $before = [];
        $after = [];

        foreach ($model->getChanges() as $key => $value) {
            if (in_array($key, ['updated_at', 'created_at'], true)) {
                continue;
            }
            $before[$key] = $model->getOriginal($key);
            $after[$key] = $value;
        }

        if ($before === [] && $after === []) {
            return;
        }

        $this->logger->record(
            action: 'updated',
            model: $model,
            before: $before,
            after: $after,
        );
    }

    public function deleted(Model $model): void
    {
        $this->logger->record(
            action: 'deleted',
            model: $model,
            before: $this->payload($model),
            after: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Model $model): array
    {
        $hidden = method_exists($model, 'getHidden') ? $model->getHidden() : [];

        return collect($model->attributesToArray())
            ->except($hidden)
            ->except(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])
            ->all();
    }
}
