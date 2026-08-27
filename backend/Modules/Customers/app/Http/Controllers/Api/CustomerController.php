<?php

namespace Modules\Customers\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Customers\Http\Requests\StoreCustomerRequest;
use Modules\Customers\Http\Requests\UpdateCustomerRequest;
use Modules\Customers\Http\Resources\CustomerResource;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerService;
use Modules\Organization\Services\OrganizationService;
use RuntimeException;

class CustomerController
{
    public function index(
        Request $request,
        OrganizationService $organizations,
        CustomerService $service,
    ): JsonResponse {
        $this->assertView($request);

        $organizationId = $organizations->current()->id;
        $service->ensureWalkIn($organizationId);

        $query = Customer::query()
            ->where('organization_id', $organizationId)
            ->with(['addresses', 'group'])
            ->orderByDesc('is_walk_in')
            ->orderBy('name');

        if ($search = $request->string('q')->trim()->toString()) {
            $escaped = str_replace(
                ['\\', '%', '_'],
                ['\\\\', '\\%', '\\_'],
                mb_strtolower($search),
            );
            $like = '%'.$escaped.'%';
            $query->where(function ($q) use ($like): void {
                $q->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(phone, '')) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(email, '')) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(account_number) LIKE ? ESCAPE '\\'", [$like]);
            });
        }

        if ($request->boolean('activeOnly')) {
            $query->where('is_active', true);
        }

        $perPage = max(1, min(100, (int) $request->integer('per_page', 15)));
        $customers = $query->paginate(perPage: $perPage);

        return response()->json([
            'data' => CustomerResource::collection($customers->items()),
            'meta' => [
                'currentPage' => $customers->currentPage(),
                'lastPage' => $customers->lastPage(),
                'total' => $customers->total(),
                'perPage' => $customers->perPage(),
            ],
        ]);
    }

    public function walkIn(
        Request $request,
        OrganizationService $organizations,
        CustomerService $service,
    ): JsonResponse {
        $this->assertView($request);

        $customer = $service->ensureWalkIn($organizations->current()->id);

        return response()->json(['data' => new CustomerResource($customer)]);
    }

    public function store(
        StoreCustomerRequest $request,
        OrganizationService $organizations,
        CustomerService $service,
    ): JsonResponse {
        try {
            $customer = $service->create(
                $organizations->current()->id,
                $request->customerAttributes(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new CustomerResource($customer)], 201);
    }

    public function show(
        Request $request,
        Customer $customer,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertView($request);
        $this->assertOrg($customer, $organizations);

        $customer->load(['addresses', 'group']);

        return response()->json(['data' => new CustomerResource($customer)]);
    }

    public function update(
        UpdateCustomerRequest $request,
        Customer $customer,
        OrganizationService $organizations,
        CustomerService $service,
    ): JsonResponse {
        $this->assertOrg($customer, $organizations);

        try {
            $customer = $service->update(
                $customer,
                $organizations->current()->id,
                $request->customerAttributes(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new CustomerResource($customer)]);
    }

    public function destroy(
        Request $request,
        Customer $customer,
        OrganizationService $organizations,
        CustomerService $service,
    ): JsonResponse {
        if ($request->user()?->hasPermission('customers.manage') !== true) {
            abort(403, 'This action is unauthorized.');
        }

        $this->assertOrg($customer, $organizations);

        try {
            $service->delete($customer);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    public function history(
        Request $request,
        Customer $customer,
        OrganizationService $organizations,
    ): JsonResponse {
        $this->assertView($request);
        $this->assertOrg($customer, $organizations);

        return response()->json([
            'data' => [
                'customerId' => $customer->id,
                'sales' => [],
                'invoices' => [],
                'payments' => [],
                'returns' => [],
                'installmentPlans' => [],
            ],
        ]);
    }

    private function assertView(Request $request): void
    {
        if ($request->user()?->hasPermission('customers.view') !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function assertOrg(Customer $customer, OrganizationService $organizations): void
    {
        if ($customer->organization_id !== $organizations->current()->id) {
            abort(404);
        }
    }
}
