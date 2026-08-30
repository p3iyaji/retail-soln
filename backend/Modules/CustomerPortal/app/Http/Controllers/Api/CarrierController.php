<?php

namespace Modules\CustomerPortal\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CustomerPortal\Http\Requests\StoreCarrierRequest;
use Modules\CustomerPortal\Http\Requests\UpdateCarrierRequest;
use Modules\CustomerPortal\Http\Resources\CarrierResource;
use Modules\CustomerPortal\Models\Carrier;
use Modules\Organization\Services\OrganizationService;

class CarrierController
{
    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);

        $query = Carrier::query()
            ->where('organization_id', $organizations->current()->id)
            ->orderBy('name');

        if ($search = $request->string('q')->trim()->toString()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('activeOnly')) {
            $query->where('is_active', true);
        }

        $perPage = max(1, min(500, (int) $request->integer('per_page', 50)));
        $carriers = $query->paginate(perPage: $perPage);

        return response()->json([
            'data' => CarrierResource::collection($carriers->items()),
            'meta' => [
                'currentPage' => $carriers->currentPage(),
                'lastPage' => $carriers->lastPage(),
                'total' => $carriers->total(),
                'perPage' => $carriers->perPage(),
            ],
        ]);
    }

    public function store(
        StoreCarrierRequest $request,
        OrganizationService $organizations,
    ): JsonResponse {
        $carrier = Carrier::query()->create([
            'organization_id' => $organizations->current()->id,
            ...$request->carrierAttributes(),
        ]);

        return response()->json(['data' => new CarrierResource($carrier)], 201);
    }

    public function show(
        Request $request,
        Carrier $carrier,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertView($request);
        $this->assertOrg($carrier, $organizations);

        return response()->json(['data' => new CarrierResource($carrier)]);
    }

    public function update(
        UpdateCarrierRequest $request,
        Carrier $carrier,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertOrg($carrier, $organizations);

        $carrier->fill($request->carrierAttributes());
        $carrier->save();

        return response()->json(['data' => new CarrierResource($carrier->fresh())]);
    }

    public function destroy(
        Request $request,
        Carrier $carrier,
        OrganizationService $organizations,
    ): JsonResponse {
        if ($request->user()?->hasPermission('carriers.manage') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $this->assertOrg($carrier, $organizations);

        if ($carrier->shipments()->exists()) {
            $carrier->is_active = false;
            $carrier->save();

            return response()->json([
                'data' => new CarrierResource($carrier->fresh()),
                'meta' => ['deactivated' => true],
            ]);
        }

        $carrier->delete();

        return response()->json(null, 204);
    }

    private function assertView(Request $request): void
    {
        $user = $request->user();
        if ($user?->hasPermission('carriers.view') !== true
            && $user?->hasPermission('fulfilment.ship') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function assertOrg(Carrier $carrier, OrganizationService $organizations): void
    {
        if ($carrier->organization_id !== $organizations->current()->id) {
            abort(404);
        }
    }
}
