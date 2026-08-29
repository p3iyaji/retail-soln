<?php

namespace Modules\CustomerPortal\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Services\PortalCheckoutService;
use Modules\CustomerPortal\Services\PortalOrganizationResolver;
use Modules\Organization\Models\Store;

class PortalCheckoutController
{
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
            'data' => $this->orderPayload($result['order'], $result['payment']->toArray()),
        ], $result['created'] ? 201 : 200);
    }

    public function confirmManual(Request $request, int $order, PortalCheckoutService $checkout): JsonResponse
    {
        /** @var CustomerAccount $account */
        $account = $request->user();

        $updated = $checkout->confirmManualPayment($account, $order);

        return response()->json([
            'data' => $this->orderPayload($updated),
        ]);
    }

    public function showOrder(Request $request, int $order): JsonResponse
    {
        /** @var CustomerAccount $account */
        $account = $request->user();

        $model = PortalOrder::query()
            ->with(['items', 'store'])
            ->where('customer_account_id', $account->id)
            ->where('id', $order)
            ->firstOrFail();

        return response()->json([
            'data' => $this->orderPayload($model),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payment
     * @return array<string, mixed>
     */
    private function orderPayload(PortalOrder $order, ?array $payment = null): array
    {
        $payload = [
            'id' => $order->id,
            'uuid' => $order->uuid,
            'orderNumber' => $order->order_number,
            'status' => $order->status,
            'paymentStatus' => $order->payment_status,
            'fulfilmentMode' => $order->fulfilment_mode,
            'store' => $order->store ? [
                'id' => $order->store->id,
                'name' => $order->store->name,
            ] : ['id' => $order->store_id, 'name' => null],
            'addressId' => $order->customer_address_id,
            'contactPhone' => $order->contact_phone,
            'totals' => [
                'subtotal' => (string) $order->subtotal,
                'discountTotal' => (string) $order->discount_total,
                'taxTotal' => (string) $order->tax_total,
                'deliveryFee' => (string) $order->delivery_fee,
                'grandTotal' => (string) $order->total,
            ],
            'paymentProvider' => $order->payment_provider,
            'paymentReference' => $order->payment_reference,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'variantId' => $item->product_variant_id,
                'productName' => $item->product_name,
                'variantName' => $item->variant_name,
                'sku' => $item->sku,
                'qty' => (string) $item->quantity,
                'unitGrossPrice' => (string) $item->unit_gross_price,
                'lineTax' => (string) $item->line_tax,
                'lineTotal' => (string) $item->line_total,
            ])->values(),
        ];

        if ($payment !== null) {
            $payload['payment'] = $payment;
        }

        return $payload;
    }
}
