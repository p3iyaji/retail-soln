<?php

namespace Modules\Pricing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Services\OrganizationService;
use Modules\Pricing\Http\Requests\StorePriceListRequest;
use Modules\Pricing\Http\Requests\UpdatePriceListRequest;
use Modules\Pricing\Http\Resources\PriceListResource;
use Modules\Pricing\Models\PriceList;
use Modules\Pricing\Services\PricingService;

class PriceListController
{
    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertManage($request);

        $lists = PriceList::query()
            ->where('organization_id', $organizations->current()->id)
            ->with('prices')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => PriceListResource::collection($lists)]);
    }

    public function store(
        StorePriceListRequest $request,
        OrganizationService $organizations,
        PricingService $pricing,
    ): JsonResponse {
        $list = $pricing->createPriceList(
            $organizations->current()->id,
            $request->attributesPayload(),
        );

        return response()->json(['data' => new PriceListResource($list->load('prices'))], 201);
    }

    public function show(
        Request $request,
        PriceList $priceList,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertManage($request);
        $this->assertOrg($priceList, $organizations);

        return response()->json(['data' => new PriceListResource($priceList->load('prices'))]);
    }

    public function update(
        UpdatePriceListRequest $request,
        PriceList $priceList,
        OrganizationService $organizations,
        PricingService $pricing,
    ): JsonResponse {
        $this->assertOrg($priceList, $organizations);

        $list = $pricing->updatePriceList(
            $priceList,
            $organizations->current()->id,
            $request->attributesPayload(),
        );

        return response()->json(['data' => new PriceListResource($list->load('prices'))]);
    }

    public function destroy(
        Request $request,
        PriceList $priceList,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertManage($request);
        $this->assertOrg($priceList, $organizations);

        $priceList->delete();

        return response()->json(null, 204);
    }

    private function assertManage(Request $request): void
    {
        if ($request->user()?->hasPermission('pricing.manage') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function assertOrg(PriceList $priceList, OrganizationService $organizations): void
    {
        if ($priceList->organization_id !== $organizations->current()->id) {
            abort(404);
        }
    }
}
