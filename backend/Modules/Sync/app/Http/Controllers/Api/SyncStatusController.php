<?php

namespace Modules\Sync\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Modules\Organization\Services\OrganizationService;
use Modules\Sync\Http\Requests\SyncStatusRequest;
use Modules\Sync\Services\SyncStatusService;

class SyncStatusController
{
    public function __invoke(
        SyncStatusRequest $request,
        OrganizationService $organizations,
        SyncStatusService $service,
    ): JsonResponse {
        $data = $service->status(
            $organizations->current()->id,
            $request->storeId(),
            $request->deviceUuid(),
        );

        return response()->json(['data' => $data]);
    }
}
