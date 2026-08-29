<?php

namespace Modules\Purchasing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;
use Modules\Purchasing\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\Http\Requests\StoreSupplierInvoiceRequest;
use Modules\Purchasing\Http\Resources\SupplierInvoiceResource;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Purchasing\Services\IdempotencyConflictException;
use Modules\Purchasing\Services\SupplierInvoiceService;
use Modules\Purchasing\Services\SupplierOutstandingService;
use RuntimeException;

class SupplierInvoiceController
{
    public function index(
        Request $request,
        OrganizationService $organizations,
        SupplierInvoiceService $invoices,
        SupplierOutstandingService $outstanding,
    ): JsonResponse {
        $this->assertPay($request);

        $request->validate([
            'status' => ['sometimes', 'nullable', 'string', Rule::enum(SupplierInvoiceStatus::class)],
            'supplierId' => ['sometimes', 'nullable', 'integer'],
        ]);

        $orgId = $organizations->current()->id;

        $query = SupplierInvoice::query()
            ->with(['payments', 'supplier', 'purchaseOrder', 'goodsReceipt'])
            ->where('organization_id', $orgId)
            ->orderByDesc('id');

        $supplierId = null;
        if ($request->filled('supplierId')) {
            $supplierId = (int) $request->integer('supplierId');
            $query->where('supplier_id', $supplierId);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $page = $query->paginate(perPage: $perPage);

        $meta = [
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'total' => $page->total(),
            'perPage' => $page->perPage(),
            'invoiceableGoodsReceipts' => $invoices->invoiceableGoodsReceipts($orgId, $supplierId),
        ];

        if ($supplierId !== null) {
            $meta['outstanding'] = $outstanding->outstandingForSupplier($orgId, $supplierId);
            $meta['supplierId'] = $supplierId;
        }

        return response()->json([
            'data' => SupplierInvoiceResource::collection($page->items()),
            'meta' => $meta,
        ]);
    }

    public function store(
        StoreSupplierInvoiceRequest $request,
        OrganizationService $organizations,
        SupplierInvoiceService $service,
        SupplierOutstandingService $outstanding,
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        if (strlen($idempotencyKey) > 64) {
            return response()->json(['message' => 'Idempotency-Key must be at most 64 characters.'], 422);
        }

        $attrs = $request->invoiceAttributes();

        try {
            $result = $service->create([
                'organizationId' => $organizations->current()->id,
                'goodsReceiptId' => $attrs['goodsReceiptId'],
                'userId' => $request->user()->id,
                'totalAmount' => $attrs['totalAmount'],
                'notes' => $attrs['notes'],
                'idempotencyKey' => $idempotencyKey,
            ]);
        } catch (IdempotencyConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $invoice = $result['invoice'];

        return response()->json([
            'data' => new SupplierInvoiceResource($invoice),
            'meta' => [
                'outstanding' => $outstanding->outstandingForSupplier(
                    $invoice->organization_id,
                    $invoice->supplier_id,
                ),
            ],
        ], $result['created'] ? 201 : 200);
    }

    public function show(
        Request $request,
        SupplierInvoice $supplierInvoice,
        OrganizationService $organizations,
        SupplierOutstandingService $outstanding,
    ): JsonResponse {
        $this->assertPay($request);
        $this->assertOrg($supplierInvoice, $organizations);

        $supplierInvoice->load(['payments', 'supplier', 'purchaseOrder', 'goodsReceipt.items']);

        return response()->json([
            'data' => new SupplierInvoiceResource($supplierInvoice),
            'meta' => [
                'outstanding' => $outstanding->outstandingForSupplier(
                    $supplierInvoice->organization_id,
                    $supplierInvoice->supplier_id,
                ),
            ],
        ]);
    }

    private function assertPay(Request $request): void
    {
        if ($request->user()?->hasPermission('purchase.pay') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function assertOrg(SupplierInvoice $invoice, OrganizationService $organizations): void
    {
        if ($invoice->organization_id !== $organizations->current()->id) {
            abort(404);
        }
    }
}
