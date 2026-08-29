<?php

namespace Modules\Sync\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Sync\Http\Requests\StoreDeviceRequest;
use Modules\Sync\Http\Requests\UpdateDeviceRequest;
use Modules\Sync\Http\Resources\DeviceResource;
use Modules\Sync\Models\Device;
use Modules\Sync\Services\DeviceService;
use RuntimeException;

class DeviceController
{
    public function index(
        Request $request,
        OrganizationService $organizations,
        DeviceService $service,
    ): JsonResponse {
        $user = $request->user();
        if ($user?->hasPermission('devices.manage') !== true) {
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
        $paginator = $service->list($orgId, $storeId, $perPage);

        return response()->json([
            'data' => DeviceResource::collection($paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(
        StoreDeviceRequest $request,
        OrganizationService $organizations,
        DeviceService $service,
    ): JsonResponse {
        try {
            $device = $service->register(
                $organizations->current()->id,
                $request->storeId(),
                $request->deviceUuid(),
                $request->deviceName(),
                $request->enabled(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new DeviceResource($device),
        ], 201);
    }

    public function update(
        UpdateDeviceRequest $request,
        Device $device,
        OrganizationService $organizations,
        DeviceService $service,
    ): JsonResponse {
        $orgId = $organizations->current()->id;
        if ((int) $device->organization_id !== $orgId) {
            abort(404);
        }

        if ((int) $device->store_id !== $request->storeId()) {
            abort(404);
        }

        $updated = $service->update($device, $request->attributesForUpdate());

        return response()->json([
            'data' => new DeviceResource($updated),
        ]);
    }

    private function resolveStoreId(Request $request): ?int
    {
        $header = $request->header('X-Store-Id');
        if ($header === null || $header === '' || ! ctype_digit((string) $header)) {
            return null;
        }

        return (int) $header;
    }
}
