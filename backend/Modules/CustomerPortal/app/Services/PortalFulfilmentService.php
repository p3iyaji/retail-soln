<?php

namespace Modules\CustomerPortal\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Support\PortalOrderStatuses;
use Modules\Inventory\Services\StockReservationService;
use RuntimeException;

/**
 * Staff pick/pack for portal orders. Consumes reserved via StockReservationService;
 * does not call POS SaleCompletionService. Stops at status processing (7.3/7.4 later).
 */
class PortalFulfilmentService
{
    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly PortalOrderPayloadBuilder $payloads,
    ) {}

    /**
     * Paid, non-cancelled orders for a store that are confirmed or processing.
     *
     * @return LengthAwarePaginator<int, PortalOrder>
     */
    public function listForStore(int $organizationId, int $storeId, int $perPage = 50): LengthAwarePaginator
    {
        return PortalOrder::query()
            ->with(['store', 'items'])
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
            ->where('payment_status', 'paid')
            ->whereNotIn('status', PortalOrderStatuses::TERMINAL)
            ->whereIn('status', [
                PortalOrderStatuses::CONFIRMED,
                PortalOrderStatuses::PROCESSING,
            ])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findForStore(int $organizationId, int $storeId, int $orderId): ?PortalOrder
    {
        return PortalOrder::query()
            ->with(['store', 'items', 'customer', 'address'])
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
            ->where('payment_status', 'paid')
            ->whereIn('status', [
                PortalOrderStatuses::CONFIRMED,
                PortalOrderStatuses::PROCESSING,
            ])
            ->whereKey($orderId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function toListItem(PortalOrder $order): array
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
            'itemCount' => $order->items->count(),
            'packedAt' => $order->packed_at?->toIso8601String(),
            'createdAt' => $order->created_at?->toIso8601String(),
            'paidAt' => $order->paid_at?->toIso8601String(),
            'packable' => $this->isPackable($order),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDetail(PortalOrder $order): array
    {
        $base = $this->payloads->build($order, enriched: true);
        $base['packedAt'] = $order->packed_at?->toIso8601String();
        $base['packable'] = $this->isPackable($order);

        return $base;
    }

    /**
     * All-or-nothing pack: consume reserved for every line, then status → processing.
     *
     * @return array{order: PortalOrder, created: bool}
     */
    public function packComplete(
        PortalOrder $order,
        int $storeId,
        int $userId,
        string $idempotencyKey,
    ): array {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw ValidationException::withMessages([
                'idempotencyKey' => ['Idempotency-Key must be between 1 and 64 characters.'],
            ]);
        }

        if ((int) $order->store_id !== $storeId) {
            throw new RuntimeException('Order does not belong to this store.');
        }

        return DB::transaction(function () use ($order, $userId, $idempotencyKey): array {
            /** @var PortalOrder $locked */
            $locked = PortalOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->load('items');

            if ($locked->status === PortalOrderStatuses::PROCESSING) {
                $storedKey = $locked->pack_idempotency_key;
                if ($storedKey !== null && $storedKey !== '' && $storedKey !== $idempotencyKey) {
                    throw new RuntimeException('Idempotency-Key conflicts with a previous pack for this order.');
                }

                return ['order' => $locked, 'created' => false];
            }

            if ($locked->payment_status !== 'paid') {
                throw new RuntimeException('Only paid orders can be packed.');
            }

            if (PortalOrderStatuses::isTerminal((string) $locked->status)) {
                throw new RuntimeException('Cancelled or terminal orders cannot be packed.');
            }

            if ($locked->status !== PortalOrderStatuses::CONFIRMED) {
                throw new RuntimeException('Only confirmed orders can be packed.');
            }

            if ($locked->items->isEmpty()) {
                throw new RuntimeException('Order has no lines to pack.');
            }

            $lines = $locked->items->map(fn ($item) => [
                'productVariantId' => (int) $item->product_variant_id,
                'quantity' => (string) $item->quantity,
            ])->values()->all();

            $this->reservations->consume([
                'organizationId' => (int) $locked->organization_id,
                'locationId' => (int) $locked->store_id,
                'lines' => $lines,
                'referenceType' => 'portal_order',
                'referenceId' => (int) $locked->id,
                'userId' => $userId,
                'notes' => 'Pack complete for portal order #'.$locked->id,
            ]);

            $locked->status = PortalOrderStatuses::PROCESSING;
            $locked->packed_at = now();
            $locked->pack_idempotency_key = $idempotencyKey;
            $locked->save();

            return ['order' => $locked->fresh(['store', 'items', 'customer', 'address']), 'created' => true];
        });
    }

    public function isPackable(PortalOrder $order): bool
    {
        return $order->payment_status === 'paid'
            && $order->status === PortalOrderStatuses::CONFIRMED
            && $order->items->isNotEmpty();
    }
}
