<?php

namespace Modules\Products\Support;

use Illuminate\Validation\Validator;
use Modules\Products\Models\Barcode;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;

final class CatalogCodeValidator
{
    /**
     * @param  array{organizationId: int, sku?: ?string, barcode?: ?string, ignoreProductId?: ?int, ignoreVariantId?: ?int}  $context
     */
    public static function validate(Validator $validator, array $context): void
    {
        $organizationId = $context['organizationId'];
        $sku = $context['sku'] ?? null;
        $barcode = $context['barcode'] ?? null;
        $ignoreProductId = $context['ignoreProductId'] ?? null;
        $ignoreVariantId = $context['ignoreVariantId'] ?? null;

        if ($sku !== null && $sku !== '') {
            $productSku = Product::query()
                ->where('organization_id', $organizationId)
                ->where('sku', $sku)
                ->when($ignoreProductId, fn ($q) => $q->where('id', '!=', $ignoreProductId))
                ->exists();

            // Default variants reuse the product SKU; ignore variants of the product being edited.
            $variantSku = ProductVariant::query()
                ->where('organization_id', $organizationId)
                ->where('sku', $sku)
                ->when($ignoreVariantId, fn ($q) => $q->where('id', '!=', $ignoreVariantId))
                ->when($ignoreProductId, fn ($q) => $q->where('product_id', '!=', $ignoreProductId))
                ->exists();

            if ($productSku || $variantSku) {
                $validator->errors()->add('sku', 'The sku has already been taken in this organization.');
            }
        }

        if ($barcode !== null && $barcode !== '') {
            $productBarcode = Product::query()
                ->where('organization_id', $organizationId)
                ->where('barcode', $barcode)
                ->when($ignoreProductId, fn ($q) => $q->where('id', '!=', $ignoreProductId))
                ->exists();

            $variantBarcode = Barcode::query()
                ->where('organization_id', $organizationId)
                ->where('code', $barcode)
                ->when($ignoreVariantId, fn ($q) => $q->where('product_variant_id', '!=', $ignoreVariantId))
                ->when(
                    $ignoreProductId,
                    fn ($q) => $q->whereDoesntHave(
                        'variant',
                        fn ($vq) => $vq->where('product_id', $ignoreProductId),
                    ),
                )
                ->exists();

            if ($productBarcode || $variantBarcode) {
                $validator->errors()->add('barcode', 'The barcode has already been taken in this organization.');
            }
        }
    }
}
