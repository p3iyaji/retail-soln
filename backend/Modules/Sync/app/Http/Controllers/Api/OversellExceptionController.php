<?php

namespace Modules\Sync\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Sync\Http\Resources\OversellExceptionResource;
use Modules\Sync\Models\InventoryOversellException;
use Modules\Sync\Services\OversellExceptionService;
use RuntimeException;

class OversellExceptionController
{
    public function approve(
        Request $request,
        InventoryOversellException $exception,
        OrganizationService $organizations,
        OversellExceptionService $service,
    ): JsonResponse {
        $user = $request->user();
        if ($user?->hasPermission('inventory.approve-oversell') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $orgId = $organizations->current()->id;
        if ($exception->organization_id !== $orgId) {
            abort(404);
        }

        if (! $user->canAccessStore($exception->store_id)) {
            abort(403, 'This action is unauthorized.');
        }

        try {
            $approved = $service->approve($exception, $user->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new OversellExceptionResource($approved),
        ]);
    }
}
