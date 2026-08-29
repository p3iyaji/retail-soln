<?php

namespace Modules\Sync\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Sync\Models\InventoryOversellException */
class OversellExceptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'saleId' => $this->sale_id,
            'saleUuid' => $this->whenLoaded('sale', fn () => $this->sale?->uuid),
            'storeId' => $this->store_id,
            'variantId' => $this->product_variant_id,
            'variantSku' => $this->whenLoaded('variant', fn () => $this->variant?->sku),
            'qtyDeficit' => (string) $this->qty_deficit,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'approvedBy' => $this->approved_by,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
