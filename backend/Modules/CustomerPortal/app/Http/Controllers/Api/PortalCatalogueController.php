<?php

namespace Modules\CustomerPortal\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CustomerPortal\Services\PortalCatalogueService;
use Modules\CustomerPortal\Services\PortalOrganizationResolver;
use Modules\CustomerPortal\Services\PortalStoreResolver;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Products\Models\Product;

class PortalCatalogueController
{
    public function categories(
        PortalOrganizationResolver $organizations,
        PortalCatalogueService $catalogue,
    ): JsonResponse {
        $organization = $organizations->resolve();
        $items = $catalogue->categories($organization);

        return response()->json([
            'data' => $items->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'parentId' => $category->parent_id,
            ])->values(),
        ]);
    }

    public function index(
        Request $request,
        PortalOrganizationResolver $organizations,
        PortalStoreResolver $stores,
        PortalCatalogueService $catalogue,
    ): JsonResponse {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'categoryId' => ['nullable', 'integer', 'min:1'],
            'storeId' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $organization = $organizations->resolve();
        $store = $stores->resolve(
            $organization,
            isset($validated['storeId']) ? (int) $validated['storeId'] : null,
        );

        $page = $catalogue->listProducts(
            $organization,
            $validated['q'] ?? null,
            isset($validated['categoryId']) ? (int) $validated['categoryId'] : null,
            (int) ($validated['per_page'] ?? 24),
        );

        $data = collect($page->items())->map(
            fn (Product $product) => $this->productCard($catalogue, $organization, $product, $store),
        );

        return response()->json([
            'data' => $data,
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
                'storeId' => $store?->id,
                'storeName' => $store?->name,
            ],
        ]);
    }

    public function show(
        Request $request,
        int $product,
        PortalOrganizationResolver $organizations,
        PortalStoreResolver $stores,
        PortalCatalogueService $catalogue,
    ): JsonResponse {
        $validated = $request->validate([
            'storeId' => ['nullable', 'integer', 'min:1'],
        ]);

        $organization = $organizations->resolve();
        $store = $stores->resolve(
            $organization,
            isset($validated['storeId']) ? (int) $validated['storeId'] : null,
        );

        $model = $catalogue->findActiveProduct($organization, $product);
        if ($model === null) {
            abort(404, 'Product not found.');
        }

        $primary = $catalogue->primaryVariant($model);
        $variants = $model->variants->map(function ($variant) use ($catalogue, $organization, $store) {
            $price = $catalogue->resolvePrice($organization, $variant, $store);
            $stock = $catalogue->stockSignal($store, $variant);

            return [
                'id' => $variant->id,
                'name' => $variant->name,
                'sku' => $variant->sku,
                'isDefault' => (bool) $variant->is_default,
                'price' => $price,
                'stock' => $stock,
            ];
        })->values();

        $image = $model->images->firstWhere('is_primary', true) ?? $model->images->first();

        return response()->json([
            'data' => [
                'id' => $model->id,
                'name' => $model->name,
                'sku' => $model->sku,
                'description' => $model->description,
                'category' => $model->category ? [
                    'id' => $model->category->id,
                    'name' => $model->category->name,
                    'slug' => $model->category->slug,
                ] : null,
                'imageUrl' => $image?->url,
                'primaryVariantId' => $primary?->id,
                'price' => $primary ? $catalogue->resolvePrice($organization, $primary, $store) : null,
                'stock' => $catalogue->stockSignal($store, $primary),
                'variants' => $variants,
                'store' => $store ? [
                    'id' => $store->id,
                    'name' => $store->name,
                ] : null,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productCard(
        PortalCatalogueService $catalogue,
        Organization $organization,
        Product $product,
        ?Store $store,
    ): array {
        $primary = $catalogue->primaryVariant($product);
        $image = $product->images->firstWhere('is_primary', true) ?? $product->images->first();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'imageUrl' => $image?->url,
            'primaryVariantId' => $primary?->id,
            'price' => $primary ? $catalogue->resolvePrice($organization, $primary, $store) : null,
            'stock' => $catalogue->stockSignal($store, $primary),
        ];
    }
}
