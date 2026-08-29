<?php

namespace Modules\Sync\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Modules\Organization\Services\OrganizationService;
use Modules\Sync\Http\Requests\SyncPushRequest;
use Modules\Sync\Services\DeviceDisabledException;
use Modules\Sync\Services\SyncPushService;

class SyncPushController
{
    public function __invoke(
        SyncPushRequest $request,
        OrganizationService $organizations,
        SyncPushService $service,
    ): JsonResponse {
        try {
            $result = $service->push(
                $organizations->current()->id,
                $request->storeId(),
                $request->user()->id,
                $request->deviceUuid(),
                $request->envelopes(),
            );
        } catch (DeviceDisabledException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'device_disabled',
            ], 403);
        }

        return response()->json([
            'data' => [
                'batchId' => $result['batchId'],
                'items' => $result['items'],
            ],
        ]);
    }
}
