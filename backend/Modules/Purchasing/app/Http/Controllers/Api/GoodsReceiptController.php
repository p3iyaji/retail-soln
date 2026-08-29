<?php

namespace Modules\Purchasing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Modules\Organization\Services\OrganizationService;
use Modules\Purchasing\Http\Requests\StoreGoodsReceiptRequest;
use Modules\Purchasing\Http\Resources\GoodsReceiptResource;
use Modules\Purchasing\Services\GoodsReceiptService;
use Modules\Purchasing\Services\IdempotencyConflictException;
use RuntimeException;

class GoodsReceiptController
{
    public function store(
        StoreGoodsReceiptRequest $request,
        OrganizationService $organizations,
        GoodsReceiptService $service,
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }

        if (strlen($idempotencyKey) > 64) {
            return response()->json(['message' => 'Idempotency-Key must be at most 64 characters.'], 422);
        }

        $attrs = $request->goodsReceiptAttributes();

        try {
            $result = $service->receive([
                'organizationId' => $organizations->current()->id,
                'purchaseOrderId' => $attrs['purchaseOrderId'],
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

        return response()->json(
            ['data' => new GoodsReceiptResource($result['receipt'])],
            $result['created'] ? 201 : 200,
        );
    }
}
