<?php

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Purchasing\Models\GoodsReceiptItem */
class GoodsReceiptItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchaseOrderItemId' => $this->purchase_order_item_id,
            'productVariantId' => $this->product_variant_id,
            'qty' => $this->qty,
            'unitCost' => $this->unit_cost,
            'qtyReturned' => $this->qtyReturned(),
            'qtyReturnable' => $this->qtyReturnable(),
            'inventoryMovementId' => $this->inventory_movement_id,
            'productVariant' => $this->whenLoaded('productVariant', fn () => $this->productVariant === null ? null : [
                'id' => $this->productVariant->id,
                'name' => $this->productVariant->name,
                'sku' => $this->productVariant->sku,
            ]),
        ];
    }
}
