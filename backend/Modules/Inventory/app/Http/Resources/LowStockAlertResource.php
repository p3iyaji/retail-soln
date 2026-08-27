<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Services\LowStockAlertService;

/** @mixin \Modules\Inventory\Models\InventoryBalance */
class LowStockAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->variant?->product;
        $available = (string) $this->available;

        return [
            'productVariantId' => $this->product_variant_id,
            'sku' => $this->variant?->sku,
            'productName' => $product?->name,
            'locationType' => $this->location_type,
            'locationId' => $this->location_id,
            'available' => $available,
            'reorderLevel' => $product?->reorder_level !== null
                ? (string) $product->reorder_level
                : null,
            'severity' => app(LowStockAlertService::class)->severityFor($available),
        ];
    }
}
