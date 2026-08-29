<?php

namespace Modules\Inventory\Http\Controllers\Api;

use App\Http\Concerns\ResolvesStoreScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Inventory\Http\Resources\StockTransferResource;
use Modules\Inventory\Models\StockTransfer;
use Modules\Inventory\Services\StockTransferService;
use Modules\Organization\Services\OrganizationService;
use RuntimeException;

class StockTransferController
{
    use ResolvesStoreScope;

    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertTransferPermission($request);

        $query = StockTransfer::query()
            ->with('items')
            ->where('organization_id', $organizations->current()->id)
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        // Hide any transfer that touches a store the caller is not assigned to.
        $storeIds = $this->scopedStoreIds($request);
        if ($storeIds !== null) {
            $query->where(function (Builder $from) use ($storeIds): void {
                $from->where('from_location_type', '!=', 'store')
                    ->orWhereIn('from_location_id', $storeIds);
            })->where(function (Builder $to) use ($storeIds): void {
                $to->where('to_location_type', '!=', 'store')
                    ->orWhereIn('to_location_id', $storeIds);
            });
        }

        $transfers = $query->paginate(perPage: (int) $request->integer('per_page', 20));

        return response()->json([
            'data' => StockTransferResource::collection($transfers->items()),
            'meta' => [
                'currentPage' => $transfers->currentPage(),
                'lastPage' => $transfers->lastPage(),
                'total' => $transfers->total(),
            ],
        ]);
    }

    public function store(
        Request $request,
        OrganizationService $organizations,
        StockTransferService $transfers,
    ): JsonResponse {
        $this->assertTransferPermission($request);
        $organizationId = $organizations->current()->id;

        $data = $request->validate([
            'fromStoreId' => ['required_without:fromWarehouseId', 'integer', Rule::exists('stores', 'id')->where('organization_id', $organizationId)],
            'fromWarehouseId' => ['required_without:fromStoreId', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $organizationId)],
            'toStoreId' => ['required_without:toWarehouseId', 'integer', Rule::exists('stores', 'id')->where('organization_id', $organizationId)],
            'toWarehouseId' => ['required_without:toStoreId', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $organizationId)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.productVariantId' => [
                'required',
                'integer',
                Rule::exists('product_variants', 'id')->where('organization_id', $organizationId),
            ],
            'items.*.quantity' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
        ]);

        $this->assertStoreAccessible($request, isset($data['fromStoreId']) ? (int) $data['fromStoreId'] : null);
        $this->assertStoreAccessible($request, isset($data['toStoreId']) ? (int) $data['toStoreId'] : null);

        try {
            $transfer = $transfers->initiate([
                'organizationId' => $organizationId,
                'fromLocationType' => isset($data['fromStoreId']) ? 'store' : 'warehouse',
                'fromLocationId' => $data['fromStoreId'] ?? $data['fromWarehouseId'],
                'toLocationType' => isset($data['toStoreId']) ? 'store' : 'warehouse',
                'toLocationId' => $data['toStoreId'] ?? $data['toWarehouseId'],
                'userId' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
                'items' => collect($data['items'])->map(fn (array $item) => [
                    'productVariantId' => $item['productVariantId'],
                    'quantity' => $item['quantity'],
                ])->all(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new StockTransferResource($transfer),
        ], 201);
    }

    public function receive(
        Request $request,
        StockTransfer $transfer,
        OrganizationService $organizations,
        StockTransferService $transfers,
    ): JsonResponse {
        $this->assertTransferPermission($request);

        if ($transfer->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        $this->assertStoreAccessible(
            $request,
            $transfer->from_location_type === 'store' ? (int) $transfer->from_location_id : null,
        );
        $this->assertStoreAccessible(
            $request,
            $transfer->to_location_type === 'store' ? (int) $transfer->to_location_id : null,
        );

        try {
            $transfer = $transfers->receive($transfer, $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new StockTransferResource($transfer),
        ]);
    }

    private function assertTransferPermission(Request $request): void
    {
        if ($request->user()?->hasPermission('inventory.transfer') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }
}
