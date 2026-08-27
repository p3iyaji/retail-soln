<?php

namespace Modules\Pricing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Pricing\Http\Requests\StorePromotionRequest;
use Modules\Pricing\Http\Requests\UpdatePromotionRequest;
use Modules\Pricing\Http\Resources\PromotionResource;
use Modules\Pricing\Models\Promotion;
use Modules\Pricing\Services\PricingService;

class PromotionController
{
    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertManage($request);

        $promotions = Promotion::query()
            ->where('organization_id', $organizations->current()->id)
            ->with('rules')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => PromotionResource::collection($promotions)]);
    }

    public function store(
        StorePromotionRequest $request,
        OrganizationService $organizations,
        PricingService $pricing,
    ): JsonResponse {
        $promotion = $pricing->createPromotion(
            $organizations->current()->id,
            $request->attributesPayload(),
        );

        return response()->json(['data' => new PromotionResource($promotion)], 201);
    }

    public function show(
        Request $request,
        Promotion $promotion,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertManage($request);
        $this->assertOrg($promotion, $organizations);

        return response()->json(['data' => new PromotionResource($promotion->load('rules'))]);
    }

    public function update(
        UpdatePromotionRequest $request,
        Promotion $promotion,
        OrganizationService $organizations,
        PricingService $pricing,
    ): JsonResponse {
        $this->assertOrg($promotion, $organizations);

        $promotion = $pricing->updatePromotion($promotion, $request->attributesPayload());

        return response()->json(['data' => new PromotionResource($promotion)]);
    }

    public function destroy(
        Request $request,
        Promotion $promotion,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertManage($request);
        $this->assertOrg($promotion, $organizations);

        $promotion->delete();

        return response()->json(null, 204);
    }

    private function assertManage(Request $request): void
    {
        if ($request->user()?->hasPermission('promotions.manage') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function assertOrg(Promotion $promotion, OrganizationService $organizations): void
    {
        if ($promotion->organization_id !== $organizations->current()->id) {
            abort(404);
        }
    }
}
