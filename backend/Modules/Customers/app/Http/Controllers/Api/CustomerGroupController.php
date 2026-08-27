<?php

namespace Modules\Customers\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Customers\Http\Requests\StoreCustomerGroupRequest;
use Modules\Customers\Http\Requests\UpdateCustomerGroupRequest;
use Modules\Customers\Http\Resources\CustomerGroupResource;
use Modules\Customers\Models\CustomerGroup;
use Modules\Customers\Services\CustomerService;
use Modules\Organization\Services\OrganizationService;

class CustomerGroupController
{
    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        $this->assertView($request);

        $groups = CustomerGroup::query()
            ->where('organization_id', $organizations->current()->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => CustomerGroupResource::collection($groups),
        ]);
    }

    public function store(
        StoreCustomerGroupRequest $request,
        OrganizationService $organizations,
        CustomerService $service,
    ): JsonResponse {
        $group = $service->createGroup(
            $organizations->current()->id,
            $request->groupAttributes(),
        );

        return response()->json(['data' => new CustomerGroupResource($group)], 201);
    }

    public function update(
        UpdateCustomerGroupRequest $request,
        CustomerGroup $customerGroup,
        OrganizationService $organizations,
        CustomerService $service,
    ): JsonResponse {
        $this->assertOrg($customerGroup, $organizations);

        $group = $service->updateGroup($customerGroup, $request->groupAttributes());

        return response()->json(['data' => new CustomerGroupResource($group)]);
    }

    public function destroy(
        Request $request,
        CustomerGroup $customerGroup,
        OrganizationService $organizations,
        CustomerService $service,
    ): JsonResponse {
        if ($request->user()?->hasPermission('customers.manage') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $this->assertOrg($customerGroup, $organizations);
        $service->deleteGroup($customerGroup);

        return response()->json(null, 204);
    }

    private function assertView(Request $request): void
    {
        if ($request->user()?->hasPermission('customers.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function assertOrg(CustomerGroup $group, OrganizationService $organizations): void
    {
        if ($group->organization_id !== $organizations->current()->id) {
            abort(404);
        }
    }
}
