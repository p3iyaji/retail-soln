<?php

namespace Modules\CustomerPortal\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Modules\CustomerPortal\Http\Requests\PortalStoreAddressRequest;
use Modules\CustomerPortal\Http\Requests\PortalUpdateAddressRequest;
use Modules\CustomerPortal\Http\Requests\PortalUpdateProfileRequest;
use Modules\CustomerPortal\Http\Resources\PortalAccountResource;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Services\PortalAccountService;
use Modules\Customers\Http\Resources\CustomerAddressResource;
use Modules\Customers\Models\CustomerAddress;

class PortalAccountController
{
    public function updateProfile(
        PortalUpdateProfileRequest $request,
        PortalAccountService $accounts,
    ): JsonResponse {
        /** @var CustomerAccount $account */
        $account = $request->user();
        $updated = $accounts->updateProfile($account, $request->validated());

        return response()->json([
            'data' => new PortalAccountResource($updated),
        ]);
    }

    public function indexAddresses(): JsonResponse
    {
        /** @var CustomerAccount $account */
        $account = request()->user();
        $account->loadMissing(['customer.addresses']);

        return response()->json([
            'data' => CustomerAddressResource::collection($account->customer?->addresses ?? collect()),
        ]);
    }

    public function storeAddress(
        PortalStoreAddressRequest $request,
        PortalAccountService $accounts,
    ): JsonResponse {
        /** @var CustomerAccount $account */
        $account = $request->user();
        $address = $accounts->createAddress($account, $request->portalPayload());

        return response()->json([
            'data' => new CustomerAddressResource($address),
        ], 201);
    }

    public function updateAddress(
        PortalUpdateAddressRequest $request,
        CustomerAddress $address,
        PortalAccountService $accounts,
    ): JsonResponse {
        /** @var CustomerAccount $account */
        $account = $request->user();
        $updated = $accounts->updateAddress($account, $address, $request->portalPayload());

        return response()->json([
            'data' => new CustomerAddressResource($updated),
        ]);
    }

    public function destroyAddress(
        CustomerAddress $address,
        PortalAccountService $accounts,
    ): JsonResponse {
        /** @var CustomerAccount $account */
        $account = request()->user();
        $accounts->deleteAddress($account, $address);

        return response()->json(null, 204);
    }
}
