<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Sales\Models\SaleReturnItem */
class SaleReturnItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'saleItemId' => $this->sale_item_id,
            'variantId' => $this->product_variant_id,
            'qty' => $this->qty,
            'unitPrice' => $this->unit_price,
            'discount' => $this->discount,
            'taxAmount' => $this->tax_amount,
            'lineTotal' => $this->line_total,
            'variant' => $this->whenLoaded('productVariant', fn () => $this->productVariant === null ? null : [
                'id' => $this->productVariant->id,
                'name' => $this->productVariant->name,
                'sku' => $this->productVariant->sku,
            ]),
        ];
    }
}
