<?php

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Purchasing\Models\PurchaseOrderItem */
class PurchaseOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productVariantId' => $this->product_variant_id,
            'qty' => $this->qty,
            'qtyReceived' => $this->qty_received,
            'qtyRemaining' => $this->qtyRemaining(),
            'unitCost' => $this->unit_cost,
            'lineTotal' => $this->line_total,
            'productVariant' => $this->whenLoaded('productVariant', fn () => $this->productVariant === null ? null : [
                'id' => $this->productVariant->id,
                'name' => $this->productVariant->name,
                'sku' => $this->productVariant->sku,
            ]),
        ];
    }
}
