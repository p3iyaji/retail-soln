<?php

namespace Modules\Purchasing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Purchasing\Http\Requests\StorePurchaseReturnRequest;
use Modules\Purchasing\Http\Resources\PurchaseReturnResource;
use Modules\Purchasing\Models\PurchaseReturn;
use Modules\Purchasing\Services\IdempotencyConflictException;
use Modules\Purchasing\Services\PurchaseReturnService;
use Modules\Purchasing\Services\SupplierOutstandingService;
use RuntimeException;

class PurchaseReturnController
{
    public function index(
        Request $request,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertReceive($request);

        $query = PurchaseReturn::query()
            ->with(['items.productVariant', 'supplier', 'store', 'purchaseOrder', 'goodsReceipt'])
            ->where('organization_id', $organizations->current()->id)
            ->orderByDesc('id');

        if ($request->filled('supplierId')) {
            $query->where('supplier_id', (int) $request->integer('supplierId'));
        }

        if ($request->filled('purchaseOrderId')) {
            $query->where('purchase_order_id', (int) $request->integer('purchaseOrderId'));
        }

        if ($request->filled('goodsReceiptId')) {
            $query->where('goods_receipt_id', (int) $request->integer('goodsReceiptId'));
        }

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $page = $query->paginate(perPage: $perPage);

        return response()->json([
            'data' => PurchaseReturnResource::collection($page->items()),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
                'perPage' => $page->perPage(),
            ],
        ]);
    }

    public function store(
        StorePurchaseReturnRequest $request,
        OrganizationService $organizations,
        PurchaseReturnService $service,
        SupplierOutstandingService $outstanding,
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        if (strlen($idempotencyKey) > 64) {
            return response()->json(['message' => 'Idempotency-Key must be at most 64 characters.'], 422);
        }

        $attrs = $request->purchaseReturnAttributes();

        try {
            $result = $service->create([
                'organizationId' => $organizations->current()->id,
                'goodsReceiptId' => $attrs['goodsReceiptId'],
                'userId' => $request->user()->id,
                'notes' => $attrs['notes'],
                'idempotencyKey' => $idempotencyKey,
                'items' => $attrs['items'],
            ]);
        } catch (IdempotencyConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $purchaseReturn = $result['purchaseReturn'];

        return response()->json([
            'data' => new PurchaseReturnResource($purchaseReturn),
            'meta' => [
                'outstanding' => $outstanding->outstandingForSupplier(
                    $purchaseReturn->organization_id,
                    $purchaseReturn->supplier_id,
                ),
            ],
        ], $result['created'] ? 201 : 200);
    }

    public function show(
        Request $request,
        PurchaseReturn $purchaseReturn,
        OrganizationService $organizations,
        SupplierOutstandingService $outstanding,
    ): JsonResponse {
        $this->assertReceive($request);
        $this->assertOrg($purchaseReturn, $organizations);

        $purchaseReturn->load(['items.productVariant', 'supplier', 'store', 'purchaseOrder', 'goodsReceipt']);

        return response()->json([
            'data' => new PurchaseReturnResource($purchaseReturn),
            'meta' => [
                'outstanding' => $outstanding->outstandingForSupplier(
                    $purchaseReturn->organization_id,
                    $purchaseReturn->supplier_id,
                ),
            ],
        ]);
    }

    private function assertReceive(Request $request): void
    {
        if ($request->user()?->hasPermission('purchase.receive') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function assertOrg(PurchaseReturn $purchaseReturn, OrganizationService $organizations): void
    {
        if ($purchaseReturn->organization_id !== $organizations->current()->id) {
            abort(404);
        }
    }
}
