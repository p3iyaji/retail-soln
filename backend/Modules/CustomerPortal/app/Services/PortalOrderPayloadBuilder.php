<?php

namespace Modules\CustomerPortal\Services;

use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Support\PortalOrderStatuses;

class PortalOrderPayloadBuilder
{
    public function __construct(
        private readonly PortalOrderTimelineService $timeline,
        private readonly PortalReturnRequestService $returns,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payment
     * @return array<string, mixed>
     */
    public function build(PortalOrder $order, ?array $payment = null, bool $enriched = false): array
    {
        $order->loadMissing(['items', 'store', 'address', 'customer', 'organization']);

        $payload = [
            'id' => $order->id,
            'uuid' => $order->uuid,
            'orderNumber' => $order->order_number,
            'status' => $order->status,
            'statusLabel' => PortalOrderStatuses::label((string) $order->status),
            'paymentStatus' => $order->payment_status,
            'fulfilmentMode' => $order->fulfilment_mode,
            'store' => $order->store ? [
                'id' => $order->store->id,
                'name' => $order->store->name,
            ] : ['id' => $order->store_id, 'name' => null],
            'addressId' => $order->customer_address_id,
            'address' => $this->addressSummary($order),
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
            'paidAt' => $order->paid_at?->toIso8601String(),
            'createdAt' => $order->created_at?->toIso8601String(),
            'invoiceAvailable' => $this->invoiceAvailable($order),
            'cancellable' => $this->cancellable($order),
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

        if ($enriched) {
            $remaining = $this->returns->remainingReturnableByItem($order);
            $activeReturn = $this->returns->activeRequestSummary($order);
            $latestReturn = $this->returns->latestRequestSummary($order);

            $payload['returnEligible'] = $this->returns->isEligible($order);
            $payload['activeReturnRequest'] = $activeReturn;
            $payload['returnRequest'] = $activeReturn ?? $latestReturn;
            $payload['items'] = $order->items->map(fn ($item) => [
                'id' => $item->id,
                'variantId' => $item->product_variant_id,
                'productName' => $item->product_name,
                'variantName' => $item->variant_name,
                'sku' => $item->sku,
                'qty' => (string) $item->quantity,
                'returnableQty' => $remaining[$item->id] ?? '0.0000',
                'unitGrossPrice' => (string) $item->unit_gross_price,
                'lineTax' => (string) $item->line_tax,
                'lineTotal' => (string) $item->line_total,
            ])->values();
            $payload['timeline'] = $this->timeline->build($order);
            $payload['customer'] = [
                'name' => $order->customer?->name,
                'email' => $order->customer?->email,
            ];
            $payload['refunds'] = [];
        }

        if ($payment !== null) {
            $payload['payment'] = $payment;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function listItem(PortalOrder $order): array
    {
        return [
            'id' => $order->id,
            'orderNumber' => $order->order_number,
            'status' => $order->status,
            'statusLabel' => PortalOrderStatuses::label((string) $order->status),
            'paymentStatus' => $order->payment_status,
            'fulfilmentMode' => $order->fulfilment_mode,
            'store' => $order->store ? [
                'id' => $order->store->id,
                'name' => $order->store->name,
            ] : ['id' => $order->store_id, 'name' => null],
            'totals' => [
                'grandTotal' => (string) $order->total,
            ],
            'invoiceAvailable' => $this->invoiceAvailable($order),
            'createdAt' => $order->created_at?->toIso8601String(),
            'paidAt' => $order->paid_at?->toIso8601String(),
        ];
    }

    public function invoiceAvailable(PortalOrder $order): bool
    {
        // Spec: when payment is paid, or order is past confirmed (fulfilment lifecycle).
        if ($order->payment_status === 'paid') {
            return true;
        }

        return in_array((string) $order->status, [
            PortalOrderStatuses::CONFIRMED,
            PortalOrderStatuses::PROCESSING,
            PortalOrderStatuses::READY_FOR_PICKUP,
            PortalOrderStatuses::OUT_FOR_DELIVERY,
            PortalOrderStatuses::DELIVERED,
        ], true);
    }

    public function cancellable(PortalOrder $order): bool
    {
        return in_array((string) $order->payment_status, ['awaiting_payment', 'failed'], true)
            && (string) $order->status === PortalOrderStatuses::PENDING;
    }

    /**
     * @return array{line1: string|null, line2: string|null, city: string|null, region: string|null, postalCode: string|null, country: string|null}|null
     */
    private function addressSummary(PortalOrder $order): ?array
    {
        $address = $order->address;
        if ($address === null) {
            return null;
        }

        return [
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'region' => $address->region,
            'postalCode' => $address->postal_code,
            'country' => $address->country,
        ];
    }
}
