<?php

namespace Modules\Products\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Products\Models\Product;

class ProductSearchService
{
    /**
     * Fast catalog search by SKU/barcode/name/category for POS and admin.
     *
     * @return Collection<int, Product>
     */
    public function search(int $organizationId, string $query, ?int $categoryId = null, int $limit = 25): Collection
    {
        $term = trim($query);
        $maxLimit = max(1, (int) config('performance.search.max_limit', 50));
        $limit = max(1, min($limit, $maxLimit));
        $fuzzyMin = max(1, (int) config('performance.search.fuzzy_min_length', 2));

        if ($term !== '') {
            $exact = $this->searchExact($organizationId, $term, $categoryId, $limit);
            if ($exact->isNotEmpty()) {
                return $exact;
            }

            if (strlen($term) < $fuzzyMin) {
                return collect();
            }
        }

        return $this->searchFuzzy($organizationId, $term, $categoryId, $limit);
    }

    /**
     * Indexed equality lookups before any fuzzy LIKE sweep (SM-4 fast path).
     *
     * @return Collection<int, Product>
     */
    private function searchExact(int $organizationId, string $term, ?int $categoryId, int $limit): Collection
    {
        $builder = Product::query()
            ->select('products.*')
            ->distinct()
            ->with(['category:id,name', 'brand:id,name', 'variants.barcodes'])
            ->leftJoin('product_variants as search_variants', function ($join) use ($organizationId): void {
                $join->on('search_variants.product_id', '=', 'products.id')
                    ->where('search_variants.organization_id', '=', $organizationId)
                    ->where('search_variants.is_active', '=', true);
            })
            ->leftJoin('barcodes as search_barcodes', function ($join) use ($organizationId): void {
                $join->on('search_barcodes.product_variant_id', '=', 'search_variants.id')
                    ->where('search_barcodes.organization_id', '=', $organizationId);
            })
            ->where('products.organization_id', $organizationId)
            ->where('products.is_active', true)
            ->where(function (Builder $q) use ($term): void {
                $q->where('products.sku', $term)
                    ->orWhere('products.barcode', $term)
                    ->orWhere('search_variants.sku', $term)
                    ->orWhere('search_barcodes.code', $term);
            });

        if ($categoryId !== null) {
            $builder->where('products.category_id', $categoryId);
        }

        return $builder
            ->orderByRaw('CASE WHEN products.sku = ? THEN 0 WHEN products.barcode = ? THEN 1 ELSE 2 END', [$term, $term])
            ->orderBy('products.name')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    private function searchFuzzy(int $organizationId, string $term, ?int $categoryId, int $limit): Collection
    {
        $builder = Product::query()
            ->with(['category:id,name', 'brand:id,name', 'variants.barcodes'])
            ->where('organization_id', $organizationId)
            ->where('is_active', true);

        if ($categoryId !== null) {
            $builder->where('category_id', $categoryId);
        }

        if ($term !== '') {
            $like = '%'.$term.'%';
            $builder->where(function (Builder $q) use ($term, $like): void {
                $q->where('sku', $term)
                    ->orWhere('barcode', $term)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhereHas('category', fn (Builder $cq) => $cq->where('name', 'like', $like))
                    ->orWhereHas('variants', function (Builder $vq) use ($term, $like): void {
                        $vq->where('sku', $term)
                            ->orWhere('sku', 'like', $like)
                            ->orWhereHas('barcodes', fn (Builder $bq) => $bq->where('code', $term)->orWhere('code', 'like', $like));
                    });
            });
        }

        return $builder
            ->orderByRaw('CASE WHEN sku = ? THEN 0 WHEN barcode = ? THEN 1 ELSE 2 END', [$term, $term])
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }
}
