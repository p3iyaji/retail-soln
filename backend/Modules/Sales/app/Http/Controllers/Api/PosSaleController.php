<?php

namespace Modules\Sales\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Http\Requests\StoreSaleRequest;
use Modules\Sales\Http\Resources\SaleResource;
use Modules\Sales\Services\IdempotencyConflictException;
use Modules\Sales\Services\SaleCompletionService;
use RuntimeException;

class PosSaleController
{
    public function store(
        StoreSaleRequest $request,
        OrganizationService $organizations,
        SaleCompletionService $service,
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        if (strlen($idempotencyKey) > 64) {
            return response()->json(['message' => 'Idempotency-Key must be at most 64 characters.'], 422);
        }

        $attrs = $request->saleAttributes();
        $storeId = $request->storeId();

        try {
            $result = $service->complete([
                'organizationId' => $organizations->current()->id,
                'storeId' => $storeId,
                'userId' => $request->user()->id,
                'uuid' => $attrs['uuid'],
                'customerId' => $attrs['customerId'],
                'idempotencyKey' => $idempotencyKey,
                'items' => $attrs['items'],
                'payments' => $attrs['payments'],
            ]);
        } catch (IdempotencyConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new SaleResource($result['sale']),
        ], $result['created'] ? 201 : 200);
    }
}
