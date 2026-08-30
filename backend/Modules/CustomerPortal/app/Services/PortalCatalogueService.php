<?php

namespace Modules\CustomerPortal\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Pricing\Services\PricingService;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use RuntimeException;

class PortalCatalogueService
{
    public function __construct(
        private readonly PricingService $pricing,
    ) {}

    /**
     * @return Collection<int, Category>
     */
    public function categories(Organization $organization): Collection
    {
        return Category::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function listProducts(
        Organization $organization,
        ?string $query,
        ?int $categoryId,
        int $perPage = 24,
    ): LengthAwarePaginator {
        $builder = $this->activeProductsQuery($organization->id);

        if ($categoryId !== null) {
            $builder->where('category_id', $categoryId);
        }

        $term = trim((string) $query);
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $builder->where(function (Builder $q) use ($term, $like): void {
                $q->where('sku', $term)
                    ->orWhere('barcode', $term)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhereHas('category', fn (Builder $cq) => $cq->where('name', 'like', $like))
                    ->orWhereHas('variants', function (Builder $vq) use ($term, $like): void {
                        $vq->where('is_active', true)
                            ->where(function (Builder $inner) use ($term, $like): void {
                                $inner->where('sku', $term)
                                    ->orWhere('sku', 'like', $like)
                                    ->orWhereHas('barcodes', fn (Builder $bq) => $bq->where('code', $term)->orWhere('code', 'like', $like));
                            });
                    });
            });

            $builder->orderByRaw(
                'CASE WHEN sku = ? THEN 0 WHEN barcode = ? THEN 1 ELSE 2 END',
                [$term, $term],
            );
        }

        return $builder
            ->orderBy('name')
            ->paginate(perPage: max(1, min($perPage, 50)));
    }

    public function findActiveProduct(Organization $organization, int $productId): ?Product
    {
        return $this->activeProductsQuery($organization->id)
            ->where('id', $productId)
            ->first();
    }

    /**
     * Primary (default) active variant for list cards, else first active by sort.
     */
    public function primaryVariant(Product $product): ?ProductVariant
    {
        $variants = $product->relationLoaded('variants')
            ? $product->variants->where('is_active', true)->values()
            : $product->variants()->where('is_active', true)->orderBy('sort_order')->get();

        if ($variants->isEmpty()) {
            return null;
        }

        return $variants->firstWhere('is_default', true) ?? $variants->first();
    }

    /**
     * @return array{amount: string, listPrice: string, grossPrice: string, netPrice: string, taxAmount: string, variantId: int}|null
     */
    public function resolvePrice(Organization $organization, ProductVariant $variant, ?Store $store): ?array
    {
        if ($store === null) {
            return null;
        }

        try {
            $resolved = $this->pricing->resolve(
                $organization->id,
                $variant->id,
                $store->id,
                '1',
            );
        } catch (RuntimeException) {
            return null;
        }

        return [
            'amount' => $resolved['grossPrice'],
            'listPrice' => $resolved['listPrice'],
            'grossPrice' => $resolved['grossPrice'],
            'netPrice' => $resolved['netPrice'],
            'taxAmount' => $resolved['tax']['amount'],
            'variantId' => $resolved['variantId'],
        ];
    }

    /**
     * Read-only customer-facing stock signal for a variant at a store (no ledger writes).
     * Omits warehouse quantity internals from the public payload.
     *
     * @return array{
     *   inStock: bool|null,
     *   storeId: int|null,
     *   storeName: string|null,
     *   label: string
     * }
     */
    public function stockSignal(?Store $store, ?ProductVariant $variant): array
    {
        if ($store === null || $variant === null) {
            return [
                'inStock' => null,
                'storeId' => $store?->id,
                'storeName' => $store?->name,
                'label' => 'Check availability',
            ];
        }

        $balance = InventoryBalance::query()
            ->where('organization_id', $store->organization_id)
            ->where('location_type', LocationType::Store->value)
            ->where('location_id', $store->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        if ($balance === null) {
            return [
                'inStock' => false,
                'storeId' => $store->id,
                'storeName' => $store->name,
                'label' => 'Out of stock',
            ];
        }

        $inStock = bccomp((string) $balance->available, '0', 4) > 0;

        return [
            'inStock' => $inStock,
            'storeId' => $store->id,
            'storeName' => $store->name,
            'label' => $inStock
                ? 'In stock · '.$store->name
                : 'Out of stock',
        ];
    }

    /**
     * @return Builder<Product>
     */
    private function activeProductsQuery(int $organizationId): Builder
    {
        return Product::query()
            ->with([
                'category:id,name,slug',
                'images',
                'variants' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->with('images'),
            ])
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereHas('variants', fn (Builder $q) => $q->where('is_active', true));
    }
}
