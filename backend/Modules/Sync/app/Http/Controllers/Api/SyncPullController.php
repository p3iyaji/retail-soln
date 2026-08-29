<?php

namespace Modules\Sync\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Modules\Organization\Services\OrganizationService;
use Modules\Sync\Http\Requests\SyncPullRequest;
use Modules\Sync\Services\DeviceDisabledException;
use Modules\Sync\Services\DeviceUnregisteredException;
use Modules\Sync\Services\InvalidPullCursorException;
use Modules\Sync\Services\SyncPullService;

class SyncPullController
{
    public function __invoke(
        SyncPullRequest $request,
        OrganizationService $organizations,
        SyncPullService $service,
    ): JsonResponse {
        try {
            $result = $service->pull(
                $organizations->current()->id,
                $request->storeId(),
                $request->deviceUuid(),
                $request->cursor(),
                $request->limit(),
            );
        } catch (DeviceUnregisteredException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'device_unregistered',
            ], 403);
        } catch (DeviceDisabledException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'device_disabled',
            ], 403);
        } catch (InvalidPullCursorException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'invalid_cursor',
            ], 422);
        }

        return response()->json($result);
    }
}
