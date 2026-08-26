<?php

namespace Modules\Organization\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Http\Requests\StoreStoreRequest;
use Modules\Organization\Http\Requests\UpdateStoreRequest;
use Modules\Organization\Http\Resources\StoreResource;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;

class StoreController
{
    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->authorize($request, 'viewAny', Store::class);

        $organization = $organizations->current();
        $query = Store::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name');

        if (! $request->user()->isOrgWide()) {
            $query->whereIn('id', $request->user()->accessibleStoreIds());
        }

        $stores = $query->paginate(perPage: (int) $request->integer('per_page', 15));

        return response()->json([
            'data' => StoreResource::collection($stores->items()),
            'meta' => [
                'currentPage' => $stores->currentPage(),
                'lastPage' => $stores->lastPage(),
                'total' => $stores->total(),
            ],
        ]);
    }

    public function store(
        StoreStoreRequest $request,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->authorize($request, 'create', Store::class);

        $organization = $organizations->current();
        $store = $organization->stores()->create($request->storeAttributes());

        return response()->json([
            'data' => new StoreResource($store),
        ], 201);
    }

    public function show(Request $request, Store $store): JsonResponse
    {
        $this->authorize($request, 'view', $store);

        return response()->json([
            'data' => new StoreResource($store),
        ]);
    }

    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        $this->authorize($request, 'update', $store);

        $store->fill($request->storeAttributes());
        $store->save();

        return response()->json([
            'data' => new StoreResource($store->fresh()),
        ]);
    }

    public function destroy(Request $request, Store $store): JsonResponse
    {
        $this->authorize($request, 'delete', $store);

        $store->delete();

        return response()->json([
            'message' => 'Store deleted.',
        ]);
    }

    private function authorize(Request $request, string $ability, mixed $model): void
    {
        if ($request->user()?->can($ability, $model) !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }
}
