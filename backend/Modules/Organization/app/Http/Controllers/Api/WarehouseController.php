<?php

namespace Modules\Organization\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Http\Requests\StoreWarehouseRequest;
use Modules\Organization\Http\Requests\UpdateWarehouseRequest;
use Modules\Organization\Http\Resources\WarehouseResource;
use Modules\Organization\Models\Warehouse;
use Modules\Organization\Services\OrganizationService;

class WarehouseController
{
    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->authorize($request, 'viewAny', Warehouse::class);

        $organization = $organizations->current();
        $warehouses = Warehouse::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->paginate(perPage: (int) $request->integer('per_page', 15));

        return response()->json([
            'data' => WarehouseResource::collection($warehouses->items()),
            'meta' => [
                'currentPage' => $warehouses->currentPage(),
                'lastPage' => $warehouses->lastPage(),
                'total' => $warehouses->total(),
            ],
        ]);
    }

    public function store(
        StoreWarehouseRequest $request,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->authorize($request, 'create', Warehouse::class);

        $organization = $organizations->current();
        $warehouse = $organization->warehouses()->create($request->warehouseAttributes());

        return response()->json([
            'data' => new WarehouseResource($warehouse),
        ], 201);
    }

    public function show(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorize($request, 'view', $warehouse);

        return response()->json([
            'data' => new WarehouseResource($warehouse),
        ]);
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorize($request, 'update', $warehouse);

        $warehouse->fill($request->warehouseAttributes());
        $warehouse->save();

        return response()->json([
            'data' => new WarehouseResource($warehouse->fresh()),
        ]);
    }

    public function destroy(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorize($request, 'delete', $warehouse);

        $warehouse->delete();

        return response()->json([
            'message' => 'Warehouse deleted.',
        ]);
    }

    private function authorize(Request $request, string $ability, mixed $model): void
    {
        if ($request->user()?->can($ability, $model) !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }
}
