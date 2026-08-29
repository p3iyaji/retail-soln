<?php

namespace Modules\Sync\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Sync\Http\Resources\OversellExceptionResource;
use Modules\Sync\Models\InventoryOversellException;
use Modules\Sync\Services\OversellExceptionService;
use RuntimeException;

class OversellExceptionController
{
    public function index(
        Request $request,
        OrganizationService $organizations,
        OversellExceptionService $service,
    ): JsonResponse {
        $user = $request->user();
        if ($user?->hasPermission('inventory.approve-oversell') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $storeId = $this->resolveStoreId($request);
        if ($storeId === null) {
            return response()->json(['message' => 'X-Store-Id header is required.'], 422);
        }

        $orgId = $organizations->current()->id;
        $exists = Store::query()
            ->where('organization_id', $orgId)
            ->whereKey($storeId)
            ->exists();

        if (! $exists) {
            return response()->json(['message' => 'Store not found.'], 422);
        }

        if (! $user->canAccessStore($storeId)) {
            abort(403, 'This action is unauthorized.');
        }

        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);
        $paginator = $service->listPending($orgId, $storeId, $perPage);

        return response()->json([
            'data' => OversellExceptionResource::collection($paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

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

    private function resolveStoreId(Request $request): ?int
    {
        $header = $request->header('X-Store-Id');
        if ($header === null || $header === '' || ! ctype_digit((string) $header)) {
            $query = $request->query('storeId');
            if ($query === null || $query === '' || ! ctype_digit((string) $query)) {
                return null;
            }

            return (int) $query;
        }

        return (int) $header;
    }
}
