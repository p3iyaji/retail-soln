<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Inventory\Models\StockAdjustment */
class StockAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizationId' => $this->organization_id,
            'locationType' => $this->location_type,
            'locationId' => $this->location_id,
            'productVariantId' => $this->product_variant_id,
            'quantity' => $this->quantity,
            'reason' => $this->reason,
            'userId' => $this->user_id,
            'inventoryMovementId' => $this->inventory_movement_id,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
