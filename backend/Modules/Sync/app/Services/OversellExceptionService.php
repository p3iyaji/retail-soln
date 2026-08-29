<?php

namespace Modules\Sync\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sync\Enums\OversellExceptionStatus;
use Modules\Sync\Models\InventoryOversellException;
use RuntimeException;

class OversellExceptionService
{
    /**
     * @return LengthAwarePaginator<int, InventoryOversellException>
     */
    public function listPending(int $organizationId, int $storeId, int $perPage = 50): LengthAwarePaginator
    {
        return InventoryOversellException::query()
            ->with(['sale', 'variant'])
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
            ->where('status', OversellExceptionStatus::Pending)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function countPending(int $organizationId, int $storeId): int
    {
        return InventoryOversellException::query()
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
            ->where('status', OversellExceptionStatus::Pending)
            ->count();
    }

    public function approve(InventoryOversellException $exception, int $approverUserId): InventoryOversellException
    {
        if ($exception->status !== OversellExceptionStatus::Pending) {
            throw new RuntimeException('Only pending oversell exceptions can be approved.');
        }

        $exception->forceFill([
            'status' => OversellExceptionStatus::Approved,
            'approved_by' => $approverUserId,
            'approved_at' => now(),
        ])->save();

        return $exception->fresh(['sale', 'variant', 'approver']);
    }
}
