<?php

namespace Modules\Audit\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Audit\Models\AuditLog;

class AuditLogController
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('audit.view')) {
            abort(403, 'This action is unauthorized.');
        }

        $query = AuditLog::query()->orderByDesc('id');

        if ($request->filled('storeId')) {
            $storeId = (int) $request->integer('storeId');
            if (! $request->user()->canAccessStore($storeId)) {
                abort(403, 'This action is unauthorized.');
            }
            $query->where('store_id', $storeId);
        } elseif (! $request->user()->isOrgWide()) {
            $query->whereIn('store_id', $request->user()->accessibleStoreIds());
        }

        if ($request->filled('auditableType')) {
            $query->where('auditable_type', $request->string('auditableType')->toString());
        }

        $logs = $query->paginate(perPage: (int) $request->integer('per_page', 25));

        return response()->json([
            'data' => collect($logs->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'organizationId' => $log->organization_id,
                'storeId' => $log->store_id,
                'userId' => $log->user_id,
                'action' => $log->action,
                'auditableType' => $log->auditable_type,
                'auditableId' => $log->auditable_id,
                'before' => $log->before,
                'after' => $log->after,
                'ipAddress' => $log->ip_address,
                'createdAt' => $log->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'currentPage' => $logs->currentPage(),
                'lastPage' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function update(): JsonResponse
    {
        return response()->json([
            'message' => 'Audit logs cannot be updated.',
        ], 405);
    }

    public function destroy(): JsonResponse
    {
        return response()->json([
            'message' => 'Audit logs cannot be deleted.',
        ], 405);
    }
}
