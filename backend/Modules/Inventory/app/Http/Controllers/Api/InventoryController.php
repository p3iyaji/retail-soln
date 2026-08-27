<?php

namespace Modules\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Resources\InventoryBalanceResource;
use Modules\Inventory\Http\Resources\InventoryMovementResource;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Organization\Services\OrganizationService;

class InventoryController
{
    public function balances(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);

        $organizationId = $organizations->current()->id;
        $query = InventoryBalance::query()
            ->where('organization_id', $organizationId)
            ->orderBy('id');

        if ($request->filled('storeId')) {
            $query->where('location_type', 'store')
                ->where('location_id', $request->integer('storeId'));
        }

        if ($request->filled('warehouseId')) {
            $query->where('location_type', 'warehouse')
                ->where('location_id', $request->integer('warehouseId'));
        }

        if ($request->filled('productVariantId')) {
            $query->where('product_variant_id', $request->integer('productVariantId'));
        }

        $balances = $query->paginate(perPage: (int) $request->integer('per_page', 50));

        return response()->json([
            'data' => InventoryBalanceResource::collection($balances->items()),
            'meta' => [
                'currentPage' => $balances->currentPage(),
                'lastPage' => $balances->lastPage(),
                'total' => $balances->total(),
            ],
        ]);
    }

    public function movements(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);

        $organizationId = $organizations->current()->id;
        $query = InventoryMovement::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id');

        if ($request->filled('storeId')) {
            $query->where('location_type', 'store')
                ->where('location_id', $request->integer('storeId'));
        }

        if ($request->filled('productVariantId')) {
            $query->where('product_variant_id', $request->integer('productVariantId'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        $movements = $query->paginate(perPage: (int) $request->integer('per_page', 50));

        return response()->json([
            'data' => InventoryMovementResource::collection($movements->items()),
            'meta' => [
                'currentPage' => $movements->currentPage(),
                'lastPage' => $movements->lastPage(),
                'total' => $movements->total(),
            ],
        ]);
    }

    private function assertView(Request $request): void
    {
        if ($request->user()?->hasPermission('inventory.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }
}
