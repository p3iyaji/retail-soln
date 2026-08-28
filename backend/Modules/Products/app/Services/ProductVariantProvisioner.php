<?php

namespace Modules\Products\Services;

use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;

class ProductVariantProvisioner
{
    public function ensureDefaultVariant(Product $product, ?string $name = null): ProductVariant
    {
        $existing = $product->variants()->where('is_default', true)->first()
            ?? $product->variants()->orderBy('sort_order')->first();

        if ($existing !== null) {
            return $existing;
        }

        $variant = $product->variants()->create([
            'organization_id' => $product->organization_id,
            'name' => $name ?? 'Default',
            'sku' => $product->sku,
            'price' => $product->base_price,
            'cost_price' => $product->cost_price,
            'is_default' => true,
            'is_active' => (bool) $product->is_active,
            'sort_order' => 0,
        ]);

        if ($product->barcode !== null && $product->barcode !== '') {
            $variant->barcodes()->create([
                'organization_id' => $product->organization_id,
                'code' => $product->barcode,
                'is_primary' => true,
            ]);
        }

        return $variant;
    }
}
