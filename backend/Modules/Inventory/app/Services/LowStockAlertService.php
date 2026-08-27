<?php

namespace Modules\Inventory\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Inventory\Models\InventoryBalance;

/**
 * Read-side low-stock detection. Never mutates balances (AD-2).
 */
class LowStockAlertService
{
    /**
     * Balances where available ≤ product reorder_level (null reorder excluded).
     * Active products/variants only. Critical (available ≤ 0) ordered first.
     *
     * Callers must not pass both storeId and warehouseId; validate upstream.
     *
     * @return Builder<InventoryBalance>
     */
    public function query(int $organizationId, ?int $storeId = null, ?int $warehouseId = null): Builder
    {
        $query = InventoryBalance::query()
            ->select('inventory_balances.*')
            ->join('product_variants', 'product_variants.id', '=', 'inventory_balances.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('inventory_balances.organization_id', $organizationId)
            ->where('products.is_active', true)
            ->where('product_variants.is_active', true)
            ->whereNotNull('products.reorder_level')
            ->whereColumn('inventory_balances.available', '<=', 'products.reorder_level')
            ->with(['variant.product'])
            ->orderByRaw('CASE WHEN inventory_balances.available <= 0 THEN 0 ELSE 1 END')
            ->orderBy('inventory_balances.id');

        if ($storeId !== null) {
            $query->where('inventory_balances.location_type', 'store')
                ->where('inventory_balances.location_id', $storeId);
        }

        if ($warehouseId !== null) {
            $query->where('inventory_balances.location_type', 'warehouse')
                ->where('inventory_balances.location_id', $warehouseId);
        }

        return $query;
    }

    /**
     * @return LengthAwarePaginator<int, InventoryBalance>
     */
    public function paginate(
        int $organizationId,
        ?int $storeId = null,
        ?int $warehouseId = null,
        int $perPage = 50,
    ): LengthAwarePaginator {
        return $this->query($organizationId, $storeId, $warehouseId)->paginate($perPage);
    }

    public function count(int $organizationId, ?int $storeId = null, ?int $warehouseId = null): int
    {
        return $this->query($organizationId, $storeId, $warehouseId)->count();
    }

    public function severityFor(string $available): string
    {
        return bccomp($available, '0', 4) <= 0 ? 'critical' : 'warning';
    }
}
