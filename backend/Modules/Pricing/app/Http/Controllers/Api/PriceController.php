<?php

namespace Modules\Pricing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Pricing\Http\Requests\StorePriceRequest;
use Modules\Pricing\Http\Resources\PriceResource;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Services\PricingService;

class PriceController
{
    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertManage($request);

        $query = Price::query()
            ->where('organization_id', $organizations->current()->id)
            ->orderBy('id');

        if ($request->filled('priceListId')) {
            $query->where('price_list_id', $request->integer('priceListId'));
        }

        $prices = $query->paginate(perPage: max(1, min(100, (int) $request->integer('per_page', 50))));

        return response()->json([
            'data' => PriceResource::collection($prices->items()),
            'meta' => [
                'currentPage' => $prices->currentPage(),
                'lastPage' => $prices->lastPage(),
                'total' => $prices->total(),
                'perPage' => $prices->perPage(),
            ],
        ]);
    }

    public function store(
        StorePriceRequest $request,
        OrganizationService $organizations,
        PricingService $pricing,
    ): JsonResponse {
        $price = $pricing->upsertPrice(
            $organizations->current()->id,
            $request->attributesPayload(),
        );

        return response()->json(['data' => new PriceResource($price)], 201);
    }

    public function update(
        StorePriceRequest $request,
        Price $price,
        OrganizationService $organizations,
        PricingService $pricing,
    ): JsonResponse {
        $this->assertOrg($price, $organizations);

        $payload = $request->attributesPayload();
        // Ensure update targets the route model identity when client sends matching keys
        $payload['price_list_id'] = $price->price_list_id;
        $payload['product_variant_id'] = $price->product_variant_id;

        $updated = $pricing->upsertPrice($organizations->current()->id, $payload);

        return response()->json(['data' => new PriceResource($updated)]);
    }

    public function destroy(
        Request $request,
        Price $price,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertManage($request);
        $this->assertOrg($price, $organizations);

        $price->delete();

        return response()->json(null, 204);
    }

    private function assertManage(Request $request): void
    {
        if ($request->user()?->hasPermission('pricing.manage') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function assertOrg(Price $price, OrganizationService $organizations): void
    {
        if ($price->organization_id !== $organizations->current()->id) {
            abort(404);
        }
    }
}
