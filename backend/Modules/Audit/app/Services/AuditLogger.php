<?php

namespace Modules\Audit\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Modules\Audit\Models\AuditLog;

class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        Model $model,
        ?array $before = null,
        ?array $after = null,
        ?int $storeId = null,
        ?int $organizationId = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'organization_id' => $organizationId
                ?? $model->getAttribute('organization_id'),
            'store_id' => $storeId
                ?? $model->getAttribute('store_id'),
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'before' => $before,
            'after' => $after,
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);
    }
}
