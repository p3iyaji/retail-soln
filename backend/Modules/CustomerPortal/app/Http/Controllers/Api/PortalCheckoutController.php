<?php

namespace Modules\CustomerPortal\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Services\PortalCheckoutService;
use Modules\CustomerPortal\Services\PortalOrderPayloadBuilder;
use Modules\CustomerPortal\Services\PortalOrganizationResolver;
use Modules\Organization\Models\Store;

class PortalCheckoutController
{
    public function __construct(
        private readonly PortalOrderPayloadBuilder $payloads,
    ) {}

    public function stores(PortalOrganizationResolver $organizations): JsonResponse
    {
        $organization = $organizations->resolve();
        $stores = Store::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return response()->json([
            'data' => $stores->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'code' => $store->code,
            ])->values(),
        ]);
    }

    public function preview(Request $request, PortalCheckoutService $checkout): JsonResponse
    {
        $validated = $request->validate([
            'fulfilmentMode' => ['required', 'string', 'in:home_delivery,click_collect,store_pickup'],
            'storeId' => ['nullable', 'integer', 'min:1'],
            'addressId' => ['nullable', 'integer', 'min:1'],
            'contactPhone' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var CustomerAccount $account */
        $account = $request->user();

        return response()->json([
            'data' => $checkout->preview($account, $validated),
        ]);
    }

    public function place(Request $request, PortalCheckoutService $checkout): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return response()->json(['message' => 'Idempotency-Key header is required.'], 422);
        }
        if (strlen($idempotencyKey) > 64) {
            return response()->json(['message' => 'Idempotency-Key must be at most 64 characters.'], 422);
        }

        $validated = $request->validate([
            'fulfilmentMode' => ['required', 'string', 'in:home_delivery,click_collect,store_pickup'],
            'storeId' => ['nullable', 'integer', 'min:1'],
            'addressId' => ['nullable', 'integer', 'min:1'],
            'contactPhone' => ['nullable', 'string', 'max:64'],
            'paymentProvider' => ['required', 'string', 'in:manual,stripe,paystack'],
            'successUrl' => ['nullable', 'string', 'max:2048'],
            'cancelUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        /** @var CustomerAccount $account */
        $account = $request->user();

        $result = $checkout->placeOrder($account, $validated, $idempotencyKey);

        return response()->json([
            'data' => $this->payloads->build($result['order'], $result['payment']->toArray()),
        ], $result['created'] ? 201 : 200);
    }

    public function confirmManual(Request $request, int $order, PortalCheckoutService $checkout): JsonResponse
    {
        /** @var CustomerAccount $account */
        $account = $request->user();

        $updated = $checkout->confirmManualPayment($account, $order);

        return response()->json([
            'data' => $this->payloads->build($updated),
        ]);
    }
}
