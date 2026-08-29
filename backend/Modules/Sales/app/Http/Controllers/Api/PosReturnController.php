<?php

namespace Modules\Sales\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Enums\SaleStatus;
use Modules\Sales\Http\Concerns\ResolvesPosStore;
use Modules\Sales\Http\Requests\StoreSaleReturnRequest;
use Modules\Sales\Http\Resources\ReturnableSaleResource;
use Modules\Sales\Http\Resources\SaleReturnResource;
use Modules\Sales\Models\Sale;
use Modules\Sales\Services\IdempotencyConflictException;
use Modules\Sales\Services\SaleReturnService;
use RuntimeException;

class PosReturnController
{
    use ResolvesPosStore;

    public function showReturnable(
        Request $request,
        Sale $sale,
        OrganizationService $organizations,
    ): JsonResponse {
        if ($request->user()?->hasPermission('sales.refund') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        if ($sale->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        $storeId = $this->resolvePosStoreId($request);
        if ($sale->store_id !== $storeId) {
            abort(404);
        }

        if ($sale->status !== SaleStatus::Completed) {
            return response()->json(['message' => 'Only completed sales can be returned.'], 422);
        }

        $sale->load(['items.productVariant', 'payments', 'customer', 'invoice']);

        return response()->json([
            'data' => new ReturnableSaleResource($sale),
        ]);
    }

    public function store(
        StoreSaleReturnRequest $request,
        OrganizationService $organizations,
        SaleReturnService $service,
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        if (strlen($idempotencyKey) > 64) {
            return response()->json(['message' => 'Idempotency-Key must be at most 64 characters.'], 422);
        }

        $attrs = $request->returnAttributes();

        try {
            $result = $service->create([
                'organizationId' => $organizations->current()->id,
                'storeId' => $request->storeId(),
                'userId' => $request->user()->id,
                'uuid' => $attrs['uuid'],
                'saleId' => $attrs['saleId'],
                'sessionId' => $attrs['sessionId'],
                'reason' => $attrs['reason'],
                'idempotencyKey' => $idempotencyKey,
                'items' => $attrs['items'],
                'refunds' => $attrs['refunds'],
            ]);
        } catch (IdempotencyConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new SaleReturnResource($result['saleReturn']),
        ], $result['created'] ? 201 : 200);
    }
}
