<?php

namespace Modules\Purchasing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;
use Modules\Purchasing\Enums\PurchaseOrderStatus;
use Modules\Purchasing\Http\Requests\StorePurchaseOrderRequest;
use Modules\Purchasing\Http\Requests\UpdatePurchaseOrderRequest;
use Modules\Purchasing\Http\Resources\PurchaseOrderResource;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Services\DualControlViolationException;
use Modules\Purchasing\Services\PurchaseOrderService;
use RuntimeException;

class PurchaseOrderController
{
    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);

        $request->validate([
            'status' => ['sometimes', 'nullable', 'string', Rule::enum(PurchaseOrderStatus::class)],
            'storeId' => ['sometimes', 'nullable', 'integer'],
        ]);

        $query = PurchaseOrder::query()
            ->with(['items.productVariant', 'supplier', 'store'])
            ->where('organization_id', $organizations->current()->id)
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('storeId')) {
            $query->where('store_id', (int) $request->integer('storeId'));
        }

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $orders = $query->paginate(perPage: $perPage);

        return response()->json([
            'data' => PurchaseOrderResource::collection($orders->items()),
            'meta' => [
                'currentPage' => $orders->currentPage(),
                'lastPage' => $orders->lastPage(),
                'total' => $orders->total(),
                'perPage' => $orders->perPage(),
            ],
        ]);
    }

    public function store(
        StorePurchaseOrderRequest $request,
        OrganizationService $organizations,
        PurchaseOrderService $service,
    ): JsonResponse {
        $attrs = $request->purchaseOrderAttributes();

        try {
            $po = $service->create([
                'organizationId' => $organizations->current()->id,
                'storeId' => $attrs['storeId'],
                'supplierId' => $attrs['supplierId'],
                'userId' => $request->user()->id,
                'notes' => $attrs['notes'],
                'items' => $attrs['items'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new PurchaseOrderResource($po)], 201);
    }

    public function show(
        Request $request,
        PurchaseOrder $purchaseOrder,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertView($request);
        $this->assertOrg($purchaseOrder, $organizations);

        $purchaseOrder->load(['items.productVariant', 'supplier', 'store']);

        return response()->json(['data' => new PurchaseOrderResource($purchaseOrder)]);
    }

    public function update(
        UpdatePurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        OrganizationService $organizations,
        PurchaseOrderService $service,
    ): JsonResponse {
        $this->assertOrg($purchaseOrder, $organizations);

        try {
            $po = $service->update(
                $purchaseOrder,
                $organizations->current()->id,
                $request->purchaseOrderAttributes(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new PurchaseOrderResource($po)]);
    }

    public function destroy(
        Request $request,
        PurchaseOrder $purchaseOrder,
        OrganizationService $organizations,
        PurchaseOrderService $service,
    ): JsonResponse {
        if ($request->user()?->hasPermission('purchase.create') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $this->assertOrg($purchaseOrder, $organizations);

        try {
            $service->deleteDraft($purchaseOrder);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    public function submit(
        Request $request,
        PurchaseOrder $purchaseOrder,
        OrganizationService $organizations,
        PurchaseOrderService $service,
    ): JsonResponse {
        if ($request->user()?->hasPermission('purchase.create') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $this->assertOrg($purchaseOrder, $organizations);

        try {
            $po = $service->submit($purchaseOrder);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new PurchaseOrderResource($po)]);
    }

    public function approve(
        Request $request,
        PurchaseOrder $purchaseOrder,
        OrganizationService $organizations,
        PurchaseOrderService $service,
    ): JsonResponse {
        if ($request->user()?->hasPermission('purchase.approve') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $this->assertOrg($purchaseOrder, $organizations);

        try {
            $po = $service->approve($purchaseOrder, $request->user()->id);
        } catch (DualControlViolationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new PurchaseOrderResource($po)]);
    }

    public function reject(
        Request $request,
        PurchaseOrder $purchaseOrder,
        OrganizationService $organizations,
        PurchaseOrderService $service,
    ): JsonResponse {
        if ($request->user()?->hasPermission('purchase.approve') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $this->assertOrg($purchaseOrder, $organizations);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $po = $service->reject(
                $purchaseOrder,
                $request->user()->id,
                $data['reason'] ?? null,
            );
        } catch (DualControlViolationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new PurchaseOrderResource($po)]);
    }

    private function assertView(Request $request): void
    {
        $user = $request->user();
        if ($user?->hasPermission('purchase.create') !== true
            && $user?->hasPermission('purchase.approve') !== true
            && $user?->hasPermission('purchase.receive') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function assertOrg(PurchaseOrder $purchaseOrder, OrganizationService $organizations): void
    {
        if ($purchaseOrder->organization_id !== $organizations->current()->id) {
            abort(404);
        }
    }
}
