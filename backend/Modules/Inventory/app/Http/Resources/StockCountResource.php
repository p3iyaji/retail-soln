<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Inventory\Models\StockCount */
class StockCountResource extends JsonResource
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
            'status' => $this->status,
            'userId' => $this->user_id,
            'notes' => $this->notes,
            'countedAt' => $this->counted_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'productVariantId' => $item->product_variant_id,
                'systemQuantity' => $item->system_quantity,
                'countedQuantity' => $item->counted_quantity,
                'variance' => $item->variance,
                'inventoryMovementId' => $item->inventory_movement_id,
            ])->values()->all()),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
