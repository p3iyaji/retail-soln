<?php

namespace Modules\Products\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Http\Requests\StoreProductVariantRequest;
use Modules\Products\Http\Requests\UpdateProductVariantRequest;
use Modules\Products\Http\Resources\ProductVariantResource;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;

class ProductVariantController
{
    public function index(Request $request, Product $product): JsonResponse
    {
        $this->authorize($request, 'view', $product);
        $this->ensureCurrentOrg($product);

        $variants = $product->variants()->with('barcodes')->orderBy('sort_order')->get();

        return response()->json([
            'data' => ProductVariantResource::collection($variants),
        ]);
    }

    public function store(StoreProductVariantRequest $request, Product $product): JsonResponse
    {
        $this->authorize($request, 'update', $product);
        $this->ensureCurrentOrg($product);

        $variant = DB::transaction(function () use ($request, $product) {
            if ($request->boolean('isDefault')) {
                $product->variants()->update(['is_default' => false]);
            }

            $variant = $product->variants()->create($request->variantAttributes($product));

            if ($code = $request->barcodeCode()) {
                $variant->barcodes()->create([
                    'organization_id' => $product->organization_id,
                    'code' => $code,
                    'is_primary' => true,
                ]);
            }

            return $variant->load('barcodes');
        });

        return response()->json([
            'data' => new ProductVariantResource($variant),
        ], 201);
    }

    public function show(Request $request, Product $product, ProductVariant $variant): JsonResponse
    {
        $this->authorize($request, 'view', $product);
        $this->ensureVariantBelongsToProduct($product, $variant);

        $variant->load('barcodes');

        return response()->json([
            'data' => new ProductVariantResource($variant),
        ]);
    }

    public function update(
        UpdateProductVariantRequest $request,
        Product $product,
        ProductVariant $variant,
    ): JsonResponse {
        $this->authorize($request, 'update', $product);
        $this->ensureVariantBelongsToProduct($product, $variant);

        $variant = DB::transaction(function () use ($request, $product, $variant) {
            if ($request->boolean('isDefault')) {
                $product->variants()->where('id', '!=', $variant->id)->update(['is_default' => false]);
            }

            $variant->fill($request->variantAttributes());
            $variant->save();

            if ($request->hasBarcode()) {
                $variant->barcodes()->delete();
                if ($code = $request->barcodeCode()) {
                    $variant->barcodes()->create([
                        'organization_id' => $product->organization_id,
                        'code' => $code,
                        'is_primary' => true,
                    ]);
                }
            }

            return $variant->fresh()->load('barcodes');
        });

        return response()->json([
            'data' => new ProductVariantResource($variant),
        ]);
    }

    public function destroy(Request $request, Product $product, ProductVariant $variant): JsonResponse
    {
        $this->authorize($request, 'update', $product);
        $this->ensureVariantBelongsToProduct($product, $variant);

        if ($product->variants()->count() <= 1) {
            return response()->json([
                'message' => 'Cannot delete the only variant for a product.',
            ], 422);
        }

        if ($this->variantIsReferenced($variant)) {
            return response()->json([
                'message' => 'Variant has stock or sales history and cannot be deleted.',
            ], 422);
        }

        $variant->delete();

        return response()->json([
            'message' => 'Product variant deleted.',
        ]);
    }

    private function variantIsReferenced(ProductVariant $variant): bool
    {
        return DB::table('sale_items')->where('product_variant_id', $variant->id)->exists()
            || DB::table('inventory_movements')->where('product_variant_id', $variant->id)->exists()
            || DB::table('prices')->where('product_variant_id', $variant->id)->exists()
            || DB::table('purchase_order_items')->where('product_variant_id', $variant->id)->exists()
            || DB::table('goods_receipt_items')->where('product_variant_id', $variant->id)->exists()
            || DB::table('purchase_return_items')->where('product_variant_id', $variant->id)->exists();
    }

    private function ensureCurrentOrg(Product $product): void
    {
        $organizationId = app(OrganizationService::class)->current()->id;

        if ($product->organization_id !== $organizationId) {
            abort(404);
        }
    }

    private function ensureVariantBelongsToProduct(Product $product, ProductVariant $variant): void
    {
        $this->ensureCurrentOrg($product);

        if ($variant->product_id !== $product->id) {
            abort(404);
        }
    }

    private function authorize(Request $request, string $ability, Product $product): void
    {
        if ($request->user()?->can($ability, $product) !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }
}
