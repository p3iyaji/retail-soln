<?php

namespace Modules\Sync\Services;

use Modules\Sync\Enums\OversellExceptionStatus;
use Modules\Sync\Models\InventoryOversellException;
use RuntimeException;

class OversellExceptionService
{
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
