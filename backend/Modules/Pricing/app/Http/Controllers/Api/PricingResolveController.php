<?php

namespace Modules\Pricing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Modules\Organization\Services\OrganizationService;
use Modules\Pricing\Http\Requests\ResolvePriceRequest;
use Modules\Pricing\Services\PricingService;
use RuntimeException;

class PricingResolveController
{
    public function __invoke(
        ResolvePriceRequest $request,
        OrganizationService $organizations,
        PricingService $pricing,
    ): JsonResponse {
        try {
            $result = $pricing->resolve(
                $organizations->current()->id,
                $request->integer('variantId'),
                $request->integer('storeId'),
                $request->filled('qty') ? $request->string('qty')->toString() : '1',
                $request->filled('customerId') ? $request->integer('customerId') : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }
}
