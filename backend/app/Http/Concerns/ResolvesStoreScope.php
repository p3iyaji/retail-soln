<?php

namespace App\Http\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Store-scope enforcement for endpoints that accept a store id or list rows
 * belonging to stores (AD-4). Mirrors the `ResolvesPosStore` /
 * `ResolvesReportScope` contract: 403 for an inaccessible store, and reads
 * intersected with the caller's assigned stores when no filter is supplied.
 *
 * Warehouse locations are not part of `store_user`; they stay governed by the
 * `warehouses.*` permissions and are left untouched by these helpers.
 */
trait ResolvesStoreScope
{
    protected function assertStoreAccessible(Request $request, ?int $storeId): void
    {
        if ($storeId === null) {
            return;
        }

        $user = $request->user();
        if (! $user instanceof User || ! $user->canAccessStore($storeId)) {
            abort(403, 'This action is unauthorized.');
        }
    }

    /**
     * Accessible store ids, or null when the caller is org-wide (no restriction).
     *
     * @return list<int>|null
     */
    protected function scopedStoreIds(Request $request): ?array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403, 'This action is unauthorized.');
        }

        if ($user->isOrgWide()) {
            return null;
        }

        return $user->accessibleStoreIds()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    protected function applyStoreColumnScope(Builder $query, Request $request, string $column = 'store_id'): void
    {
        $ids = $this->scopedStoreIds($request);
        if ($ids === null) {
            return;
        }

        $query->whereIn($column, $ids);
    }

    /**
     * Polymorphic `location_type` / `location_id` pairs used by the inventory ledger.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    protected function applyLocationScope(
        Builder $query,
        Request $request,
        string $typeColumn = 'location_type',
        string $idColumn = 'location_id',
    ): void {
        $ids = $this->scopedStoreIds($request);
        if ($ids === null) {
            return;
        }

        $query->where(function (Builder $outer) use ($ids, $typeColumn, $idColumn): void {
            $outer->where($typeColumn, '!=', 'store')
                ->orWhere(function (Builder $inner) use ($ids, $typeColumn, $idColumn): void {
                    $inner->where($typeColumn, 'store')->whereIn($idColumn, $ids);
                });
        });
    }
}
