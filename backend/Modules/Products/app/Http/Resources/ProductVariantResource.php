<?php

namespace Modules\Products\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Products\Models\ProductVariant */
class ProductVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primary = $this->relationLoaded('barcodes')
            ? ($this->barcodes->firstWhere('is_primary', true) ?? $this->barcodes->first())
            : $this->primaryBarcode();

        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'organizationId' => $this->organization_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $primary?->code,
            'price' => $this->price,
            'costPrice' => $this->cost_price,
            'isDefault' => $this->is_default,
            'isActive' => $this->is_active,
            'sortOrder' => $this->sort_order,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
