<?php

namespace Modules\Sales\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Enums\SaleStatus;
use Modules\Sales\Http\Concerns\ResolvesPosStore;
use Modules\Sales\Http\Requests\StoreHeldSaleRequest;
use Modules\Sales\Http\Requests\VoidSaleRequest;
use Modules\Sales\Http\Resources\SaleResource;
use Modules\Sales\Models\Sale;
use Modules\Sales\Services\SaleHoldService;
use Modules\Sales\Services\SaleVoidService;
use RuntimeException;

class PosHeldSaleController
{
    use ResolvesPosStore;

    public function hold(
        StoreHeldSaleRequest $request,
        OrganizationService $organizations,
        SaleHoldService $service,
    ): JsonResponse {
        $attrs = $request->heldSaleAttributes();

        try {
            $sale = $service->hold([
                'organizationId' => $organizations->current()->id,
                'storeId' => $request->storeId(),
                'userId' => $request->user()->id,
                'uuid' => $attrs['uuid'],
                'customerId' => $attrs['customerId'],
                'notes' => $attrs['notes'],
                'items' => $attrs['items'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new SaleResource($sale),
        ], 201);
    }

    public function index(
        Request $request,
        OrganizationService $organizations,
    ): JsonResponse {
        if ($request->user()?->hasPermission('sales.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $storeId = $this->resolvePosStoreId($request);

        $sales = Sale::query()
            ->with(['items.productVariant', 'customer'])
            ->where('organization_id', $organizations->current()->id)
            ->where('store_id', $storeId)
            ->where('status', SaleStatus::Held)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => SaleResource::collection($sales),
        ]);
    }

    public function show(
        Request $request,
        Sale $sale,
        OrganizationService $organizations,
    ): JsonResponse {
        if ($request->user()?->hasPermission('sales.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $this->assertOrg($sale, $organizations);

        $storeId = $this->resolvePosStoreId($request);
        if ($sale->store_id !== $storeId) {
            abort(404);
        }

        if ($sale->status !== SaleStatus::Held) {
            abort(404);
        }

        $sale->load(['items.productVariant', 'customer', 'store']);

        return response()->json([
            'data' => new SaleResource($sale),
        ]);
    }

    public function void(
        VoidSaleRequest $request,
        Sale $sale,
        OrganizationService $organizations,
        SaleVoidService $service,
    ): JsonResponse {
        $this->assertOrg($sale, $organizations);

        $user = $request->user();
        if ($sale->status === SaleStatus::Held) {
            if ($user?->hasPermission('sales.create') !== true) {
                abort(403, 'This action is unauthorized.');
            }
        } elseif ($sale->status === SaleStatus::Completed) {
            if ($user?->hasPermission('sales.void') !== true) {
                abort(403, 'This action is unauthorized.');
            }
        } else {
            return response()->json(['message' => 'Sale cannot be voided.'], 422);
        }

        try {
            $voided = $service->void($sale, $user->id, $request->validated('reason'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new SaleResource($voided),
        ]);
    }

    private function assertOrg(Sale $sale, OrganizationService $organizations): void
    {
        if ($sale->organization_id !== $organizations->current()->id) {
            abort(404);
        }
    }
}
