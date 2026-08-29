<?php

namespace Modules\Inventory\Http\Controllers\Api;

use App\Http\Concerns\ResolvesStoreScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Inventory\Http\Resources\StockAdjustmentResource;
use Modules\Inventory\Http\Resources\StockCountResource;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Inventory\Services\StockCountService;
use Modules\Organization\Services\OrganizationService;
use RuntimeException;

class StockMutationController
{
    use ResolvesStoreScope;

    public function storeAdjustment(
        Request $request,
        OrganizationService $organizations,
        StockAdjustmentService $adjustments,
    ): JsonResponse {
        if ($request->user()?->hasPermission('inventory.adjust') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $organizationId = $organizations->current()->id;

        $data = $request->validate([
            'storeId' => ['required_without:warehouseId', 'integer', Rule::exists('stores', 'id')->where('organization_id', $organizationId)],
            'warehouseId' => ['required_without:storeId', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $organizationId)],
            'productVariantId' => [
                'required',
                'integer',
                Rule::exists('product_variants', 'id')->where('organization_id', $organizationId),
            ],
            'quantity' => ['required', 'regex:/^-?\d+(\.\d{1,4})?$/'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->assertStoreAccessible($request, isset($data['storeId']) ? (int) $data['storeId'] : null);

        try {
            $adjustment = $adjustments->adjust([
                'organizationId' => $organizationId,
                'locationType' => isset($data['storeId']) ? 'store' : 'warehouse',
                'locationId' => $data['storeId'] ?? $data['warehouseId'],
                'productVariantId' => $data['productVariantId'],
                'quantity' => $data['quantity'],
                'reason' => $data['reason'],
                'userId' => $request->user()->id,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new StockAdjustmentResource($adjustment),
        ], 201);
    }

    public function storeCount(
        Request $request,
        OrganizationService $organizations,
        StockCountService $counts,
    ): JsonResponse {
        if ($request->user()?->hasPermission('inventory.count') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $organizationId = $organizations->current()->id;

        $data = $request->validate([
            'storeId' => ['required_without:warehouseId', 'integer', Rule::exists('stores', 'id')->where('organization_id', $organizationId)],
            'warehouseId' => ['required_without:storeId', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $organizationId)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.productVariantId' => [
                'required',
                'integer',
                Rule::exists('product_variants', 'id')->where('organization_id', $organizationId),
            ],
            'items.*.countedQuantity' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
        ]);

        $this->assertStoreAccessible($request, isset($data['storeId']) ? (int) $data['storeId'] : null);

        try {
            $count = $counts->complete([
                'organizationId' => $organizationId,
                'locationType' => isset($data['storeId']) ? 'store' : 'warehouse',
                'locationId' => $data['storeId'] ?? $data['warehouseId'],
                'userId' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
                'items' => collect($data['items'])->map(fn (array $item) => [
                    'productVariantId' => $item['productVariantId'],
                    'countedQuantity' => $item['countedQuantity'],
                ])->all(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new StockCountResource($count),
        ], 201);
    }

    public function indexCounts(Request $request, OrganizationService $organizations): JsonResponse
    {
        if ($request->user()?->hasPermission('inventory.count') !== true
            && $request->user()?->hasPermission('inventory.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $query = StockCount::query()
            ->with('items')
            ->where('organization_id', $organizations->current()->id)
            ->orderByDesc('id');

        if ($request->filled('storeId')) {
            $storeId = (int) $request->integer('storeId');
            $this->assertStoreAccessible($request, $storeId);
            $query->where('location_type', 'store')
                ->where('location_id', $storeId);
        } else {
            $this->applyLocationScope($query, $request);
        }

        $counts = $query->paginate(perPage: (int) $request->integer('per_page', 20));

        return response()->json([
            'data' => StockCountResource::collection($counts->items()),
            'meta' => [
                'currentPage' => $counts->currentPage(),
                'lastPage' => $counts->lastPage(),
                'total' => $counts->total(),
            ],
        ]);
    }
}
