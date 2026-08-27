<?php

namespace Modules\Purchasing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Purchasing\Http\Requests\StoreSupplierPaymentRequest;
use Modules\Purchasing\Http\Resources\SupplierPaymentResource;
use Modules\Purchasing\Models\SupplierPayment;
use Modules\Purchasing\Services\IdempotencyConflictException;
use Modules\Purchasing\Services\SupplierOutstandingService;
use Modules\Purchasing\Services\SupplierPaymentService;
use RuntimeException;

class SupplierPaymentController
{
    public function index(
        Request $request,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertPay($request);

        $query = SupplierPayment::query()
            ->with(['supplier', 'invoice'])
            ->where('organization_id', $organizations->current()->id)
            ->orderByDesc('id');

        if ($request->filled('supplierId')) {
            $query->where('supplier_id', (int) $request->integer('supplierId'));
        }

        if ($request->filled('supplierInvoiceId')) {
            $query->where('supplier_invoice_id', (int) $request->integer('supplierInvoiceId'));
        }

        $perPage = max(1, min(100, (int) $request->integer('per_page', 20)));
        $page = $query->paginate(perPage: $perPage);

        return response()->json([
            'data' => SupplierPaymentResource::collection($page->items()),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'total' => $page->total(),
                'perPage' => $page->perPage(),
            ],
        ]);
    }

    public function store(
        StoreSupplierPaymentRequest $request,
        OrganizationService $organizations,
        SupplierPaymentService $service,
        SupplierOutstandingService $outstanding,
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey !== null && $idempotencyKey !== '' && strlen($idempotencyKey) > 64) {
            return response()->json(['message' => 'Idempotency-Key must be at most 64 characters.'], 422);
        }

        $attrs = $request->paymentAttributes();

        try {
            $result = $service->create([
                'organizationId' => $organizations->current()->id,
                'supplierInvoiceId' => $attrs['supplierInvoiceId'],
                'userId' => $request->user()->id,
                'amount' => $attrs['amount'],
                'method' => $attrs['method'],
                'reference' => $attrs['reference'],
                'paidAt' => $attrs['paidAt'],
                'notes' => $attrs['notes'],
                'idempotencyKey' => $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : null,
            ]);
        } catch (IdempotencyConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payment = $result['payment'];

        return response()->json([
            'data' => new SupplierPaymentResource($payment),
            'meta' => [
                'outstanding' => $outstanding->outstandingForSupplier(
                    $payment->organization_id,
                    $payment->supplier_id,
                ),
            ],
        ], $result['created'] ? 201 : 200);
    }

    public function show(
        Request $request,
        SupplierPayment $supplierPayment,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertPay($request);
        $this->assertOrg($supplierPayment, $organizations);

        $supplierPayment->load(['supplier', 'invoice.payments']);

        return response()->json(['data' => new SupplierPaymentResource($supplierPayment)]);
    }

    private function assertPay(Request $request): void
    {
        if ($request->user()?->hasPermission('purchase.pay') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function assertOrg(SupplierPayment $payment, OrganizationService $organizations): void
    {
        if ($payment->organization_id !== $organizations->current()->id) {
            abort(404);
        }
    }
}
