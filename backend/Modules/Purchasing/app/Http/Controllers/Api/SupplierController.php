<?php

namespace Modules\Purchasing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Purchasing\Http\Requests\StoreSupplierRequest;
use Modules\Purchasing\Http\Requests\UpdateSupplierRequest;
use Modules\Purchasing\Http\Resources\SupplierResource;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Services\SupplierService;
use RuntimeException;

class SupplierController
{
    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);

        $query = Supplier::query()
            ->where('organization_id', $organizations->current()->id)
            ->orderBy('name');

        if ($search = $request->string('q')->trim()->toString()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('activeOnly')) {
            $query->where('is_active', true);
        }

        $lite = $request->boolean('lite');
        $with = $lite ? [] : ['contacts', 'addresses', 'productMappings.product'];
        $maxPerPage = $lite ? 500 : 100;
        $defaultPerPage = $lite ? 500 : 15;
        $perPage = max(1, min($maxPerPage, (int) $request->integer('per_page', $defaultPerPage)));

        $suppliers = $query->with($with)->paginate(perPage: $perPage);

        return response()->json([
            'data' => SupplierResource::collection($suppliers->items()),
            'meta' => [
                'currentPage' => $suppliers->currentPage(),
                'lastPage' => $suppliers->lastPage(),
                'total' => $suppliers->total(),
                'perPage' => $suppliers->perPage(),
            ],
        ]);
    }

    public function store(
        StoreSupplierRequest $request,
        OrganizationService $organizations,
        SupplierService $service,
    ): JsonResponse {
        try {
            $supplier = $service->create(
                $organizations->current()->id,
                $request->supplierAttributes(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new SupplierResource($supplier)], 201);
    }

    public function show(Request $request, Supplier $supplier, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);
        $this->assertOrg($supplier, $organizations);

        $supplier->load(['contacts', 'addresses', 'productMappings.product']);

        return response()->json(['data' => new SupplierResource($supplier)]);
    }

    public function update(
        UpdateSupplierRequest $request,
        Supplier $supplier,
        OrganizationService $organizations,
        SupplierService $service,
    ): JsonResponse {
        $this->assertOrg($supplier, $organizations);

        try {
            $supplier = $service->update(
                $supplier,
                $organizations->current()->id,
                $request->supplierAttributes(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new SupplierResource($supplier)]);
    }

    public function destroy(
        Request $request,
        Supplier $supplier,
        OrganizationService $organizations,
        SupplierService $service,
    ): JsonResponse {
        if ($request->user()?->hasPermission('suppliers.manage') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $this->assertOrg($supplier, $organizations);

        $result = $service->deleteOrDeactivate($supplier);

        if ($result['deactivated']) {
            return response()->json([
                'data' => new SupplierResource($result['supplier']),
                'meta' => ['deactivated' => true],
            ]);
        }

        return response()->json(null, 204);
    }

    private function assertView(Request $request): void
    {
        $user = $request->user();
        if ($user?->hasPermission('suppliers.view') !== true
            && $user?->hasPermission('catalog.view') !== true
            && $user?->hasPermission('products.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function assertOrg(Supplier $supplier, OrganizationService $organizations): void
    {
        if ($supplier->organization_id !== $organizations->current()->id) {
            abort(404);
        }
    }
}
