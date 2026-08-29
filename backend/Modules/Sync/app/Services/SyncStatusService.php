<?php

namespace Modules\Sync\Services;

use Modules\Sync\Enums\OversellExceptionStatus;
use Modules\Sync\Models\Device;
use Modules\Sync\Models\InventoryOversellException;
use Modules\Sync\Models\SyncBatch;

class SyncStatusService
{
    /**
     * @return array{
     *   deviceEnabled?: bool,
     *   lastPushAt: ?string,
     *   lastPullAt: ?string,
     *   pendingOversellCount: int,
     *   pendingClientHint: null
     * }
     */
    public function status(int $organizationId, int $storeId, ?string $deviceUuid): array
    {
        $pendingOversellCount = InventoryOversellException::query()
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
            ->where('status', OversellExceptionStatus::Pending)
            ->count();

        $batchQuery = SyncBatch::query()
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId);

        $device = null;
        if ($deviceUuid !== null && $deviceUuid !== '') {
            $device = Device::query()
                ->where('organization_id', $organizationId)
                ->where('store_id', $storeId)
                ->where('uuid', $deviceUuid)
                ->first();

            if ($device !== null) {
                $batchQuery->where('device_id', $device->id);
            }
        }

        $lastPushAt = $batchQuery->max('created_at');

        $payload = [
            'lastPushAt' => $lastPushAt !== null
                ? \Illuminate\Support\Carbon::parse($lastPushAt)->toIso8601String()
                : null,
            // Server does not persist pull cursors; client tracks lastPullAt in Dexie.
            'lastPullAt' => null,
            'pendingOversellCount' => $pendingOversellCount,
            'pendingClientHint' => null,
        ];

        if ($deviceUuid !== null && $deviceUuid !== '') {
            // Unknown / cross-store deviceUuid → not enabled for this store.
            $payload['deviceEnabled'] = $device !== null ? (bool) $device->enabled : false;
        }

        return $payload;
    }
}
