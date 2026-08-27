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
        $limit = max(1, min($limit, 50));

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
