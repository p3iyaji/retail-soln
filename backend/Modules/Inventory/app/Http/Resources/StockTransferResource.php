<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Inventory\Models\StockTransfer */
class StockTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizationId' => $this->organization_id,
            'fromLocationType' => $this->from_location_type,
            'fromLocationId' => $this->from_location_id,
            'toLocationType' => $this->to_location_type,
            'toLocationId' => $this->to_location_id,
            'status' => $this->status,
            'initiatedBy' => $this->initiated_by,
            'receivedBy' => $this->received_by,
            'notes' => $this->notes,
            'shippedAt' => $this->shipped_at?->toIso8601String(),
            'receivedAt' => $this->received_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'productVariantId' => $item->product_variant_id,
                'quantity' => $item->quantity,
                'transferOutMovementId' => $item->transfer_out_movement_id,
                'inTransitMovementId' => $item->in_transit_movement_id,
                'transferInMovementId' => $item->transfer_in_movement_id,
                'inTransitClearMovementId' => $item->in_transit_clear_movement_id,
            ])->values()->all()),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
