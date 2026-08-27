<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Inventory\Models\InventoryBalance */
class InventoryBalanceResource extends JsonResource
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
            'available' => $this->available,
            'reserved' => $this->reserved,
            'damaged' => $this->damaged,
            'inTransit' => $this->in_transit,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
