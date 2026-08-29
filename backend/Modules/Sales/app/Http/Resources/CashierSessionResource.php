<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Services\CashierSessionService;

/** @mixin \Modules\Sales\Models\CashierSession */
class CashierSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = null;
        if ($this->relationLoaded('cashMovements')) {
            $summary = app(CashierSessionService::class)->summarizeMovements($this->resource);
        }

        return [
            'id' => $this->id,
            'organizationId' => $this->organization_id,
            'storeId' => $this->store_id,
            'terminalId' => $this->terminal_id,
            'userId' => $this->user_id,
            'status' => $this->status?->value ?? $this->status,
            'openingFloat' => $this->opening_float,
            'expectedCash' => $this->expected_cash,
            'closingCash' => $this->closing_cash,
            'variance' => $this->variance,
            'openedAt' => $this->opened_at?->toIso8601String(),
            'closedAt' => $this->closed_at?->toIso8601String(),
            'notes' => $this->notes,
            'terminal' => $this->whenLoaded('terminal', fn () => $this->terminal === null ? null : [
                'id' => $this->terminal->id,
                'name' => $this->terminal->name,
                'uuid' => $this->terminal->uuid,
            ]),
            'cashier' => $this->whenLoaded('cashier', fn () => $this->cashier === null ? null : [
                'id' => $this->cashier->id,
                'name' => $this->cashier->name,
            ]),
            'store' => $this->whenLoaded('store', fn () => $this->store === null ? null : [
                'id' => $this->store->id,
                'name' => $this->store->name,
            ]),
            'summary' => $summary,
            'cashMovements' => CashMovementResource::collection($this->whenLoaded('cashMovements')),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
