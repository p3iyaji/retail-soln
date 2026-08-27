<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Inventory\Models\InventoryMovement */
class InventoryMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'organizationId' => $this->organization_id,
            'locationType' => $this->location_type instanceof \BackedEnum
                ? $this->location_type->value
                : $this->location_type,
            'locationId' => $this->location_id,
            'productVariantId' => $this->product_variant_id,
            'type' => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
            'quantity' => $this->quantity,
            'bucket' => $this->bucket instanceof \BackedEnum ? $this->bucket->value : $this->bucket,
            'unitCost' => $this->unit_cost,
            'referenceType' => $this->reference_type,
            'referenceId' => $this->reference_id,
            'userId' => $this->user_id,
            'notes' => $this->notes,
            'balanceAfter' => $this->balance_after,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
