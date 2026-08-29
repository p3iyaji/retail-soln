<?php

namespace Modules\CustomerPortal\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Models\PortalShipment;
use Modules\CustomerPortal\Support\PortalOrderStatuses;
use Modules\Inventory\Services\StockReservationService;
use RuntimeException;

/**
 * Staff pick/pack/ship/deliver/pickup for portal orders.
 * Pack consumes reserved via StockReservationService; ship/deliver/pickup do not touch inventory.
 */
class PortalFulfilmentService
{
    /** Active staff list (delivered excluded; history optional later). */
    /** @var list<string> */
    private const STAFF_LIST = [
        PortalOrderStatuses::CONFIRMED,
        PortalOrderStatuses::PROCESSING,
        PortalOrderStatuses::READY_FOR_PICKUP,
        PortalOrderStatuses::OUT_FOR_DELIVERY,
    ];

    /** Show/detail may still resolve delivered for refetch after deliver/collect. */
    /** @var list<string> */
    private const STAFF_VISIBLE = [
        PortalOrderStatuses::CONFIRMED,
        PortalOrderStatuses::PROCESSING,
        PortalOrderStatuses::READY_FOR_PICKUP,
        PortalOrderStatuses::OUT_FOR_DELIVERY,
        PortalOrderStatuses::DELIVERED,
    ];

    /** @var list<string> */
    private const PICKUP_MODES = ['store_pickup', 'click_collect'];

    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly PortalOrderPayloadBuilder $payloads,
    ) {}

    /**
     * Paid, non-cancelled orders for a store in the fulfilment pipeline.
     * Default list excludes delivered (history optional later).
     *
     * @return LengthAwarePaginator<int, PortalOrder>
     */
    public function listForStore(int $organizationId, int $storeId, int $perPage = 50): LengthAwarePaginator
    {
        return $this->queueQuery($organizationId, $storeId)
            ->with(['store', 'items', 'shipment'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Count of the same queue the staff list shows, optionally narrowed to a subset of its statuses.
     *
     * @param  list<string>|null  $statuses
     */
    public function queueCountForStore(int $organizationId, int $storeId, ?array $statuses = null): int
    {
        return $this->queueQuery($organizationId, $storeId, $statuses)->count();
    }

    /**
     * @param  list<string>|null  $statuses
     * @return Builder<PortalOrder>
     */
    private function queueQuery(int $organizationId, int $storeId, ?array $statuses = null): Builder
    {
        $allowed = $statuses === null
            ? self::STAFF_LIST
            : array_values(array_intersect(self::STAFF_LIST, $statuses));

        return PortalOrder::query()
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
            ->where('payment_status', 'paid')
            ->whereNotIn('status', PortalOrderStatuses::TERMINAL)
            ->whereIn('status', $allowed);
    }

    public function findForStore(int $organizationId, int $storeId, int $orderId): ?PortalOrder
    {
        return PortalOrder::query()
            ->with(['store', 'items', 'customer', 'address', 'shipment'])
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
            ->where('payment_status', 'paid')
            ->whereIn('status', self::STAFF_VISIBLE)
            ->whereKey($orderId)
            ->first();
    }

    /**
     * Mutator lookup for ready/collect — store-scoped only; payment/status validated in service (422).
     */
    public function findForStoreAction(int $organizationId, int $storeId, int $orderId): ?PortalOrder
    {
        return PortalOrder::query()
            ->with(['store', 'items', 'customer', 'address', 'shipment'])
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
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
            'statusLabel' => $this->staffStatusLabel($order),
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
            'collectedAt' => $order->collected_at?->toIso8601String(),
            'createdAt' => $order->created_at?->toIso8601String(),
            'paidAt' => $order->paid_at?->toIso8601String(),
            'packable' => $this->isPackable($order),
            'shippable' => $this->isShippable($order),
            'deliverable' => $this->isDeliverable($order),
            'readyForPickup' => $this->isReadyForPickup($order),
            'collectable' => $this->isCollectable($order),
            'shipment' => $this->shipmentSummary($order->shipment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDetail(PortalOrder $order): array
    {
        $order->loadMissing(['shipment']);
        $base = $this->payloads->build($order, enriched: true);
        $base['statusLabel'] = $this->staffStatusLabel($order);
        $base['packedAt'] = $order->packed_at?->toIso8601String();
        $base['collectedAt'] = $order->collected_at?->toIso8601String();
        $base['packable'] = $this->isPackable($order);
        $base['shippable'] = $this->isShippable($order);
        $base['deliverable'] = $this->isDeliverable($order);
        $base['readyForPickup'] = $this->isReadyForPickup($order);
        $base['collectable'] = $this->isCollectable($order);
        $base['shipment'] = $this->shipmentSummary($order->shipment);

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

            return ['order' => $locked->fresh(['store', 'items', 'customer', 'address', 'shipment']), 'created' => true];
        });
    }

    /**
     * Home-delivery ship: create thin shipment, status → out_for_delivery. No ledger posts.
     *
     * @param  array{carrierName?: ?string, trackingNumber?: ?string}  $input
     * @return array{order: PortalOrder, created: bool}
     */
    public function ship(
        PortalOrder $order,
        int $storeId,
        string $idempotencyKey,
        array $input = [],
    ): array {
        $this->assertIdempotencyKey($idempotencyKey);

        if ((int) $order->store_id !== $storeId) {
            throw new RuntimeException('Order does not belong to this store.');
        }

        $carrierName = $this->nullableTrimmed($input['carrierName'] ?? null, 120, 'carrierName');
        $trackingNumber = $this->nullableTrimmed($input['trackingNumber'] ?? null, 120, 'trackingNumber');

        return DB::transaction(function () use ($order, $idempotencyKey, $carrierName, $trackingNumber): array {
            /** @var PortalOrder $locked */
            $locked = PortalOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->load('shipment');

            $this->assertPaidHomeDelivery($locked, 'shipped');

            if ($locked->status === PortalOrderStatuses::OUT_FOR_DELIVERY
                || $locked->status === PortalOrderStatuses::DELIVERED) {
                $storedKey = $locked->shipment?->ship_idempotency_key;
                if ($storedKey === null || $storedKey === '') {
                    throw new RuntimeException('Order was already shipped; provide the original Idempotency-Key to replay.');
                }
                if ($storedKey !== $idempotencyKey) {
                    throw new RuntimeException('Idempotency-Key conflicts with a previous ship for this order.');
                }

                return [
                    'order' => $locked->fresh(['store', 'items', 'customer', 'address', 'shipment']),
                    'created' => false,
                ];
            }

            if (PortalOrderStatuses::isTerminal((string) $locked->status)) {
                throw new RuntimeException('Cancelled or terminal orders cannot be shipped.');
            }

            if ($locked->status !== PortalOrderStatuses::PROCESSING) {
                throw new RuntimeException('Only packed (processing) home_delivery orders can be shipped.');
            }

            $shipment = $locked->shipment ?? new PortalShipment([
                'organization_id' => $locked->organization_id,
                'portal_order_id' => $locked->id,
            ]);

            $shipment->organization_id = (int) $locked->organization_id;
            $shipment->portal_order_id = (int) $locked->id;
            $shipment->carrier_name = $carrierName;
            $shipment->tracking_number = $trackingNumber;
            $shipment->shipped_at = now();
            $shipment->ship_idempotency_key = $idempotencyKey;
            $shipment->save();

            $locked->status = PortalOrderStatuses::OUT_FOR_DELIVERY;
            $locked->save();

            return [
                'order' => $locked->fresh(['store', 'items', 'customer', 'address', 'shipment']),
                'created' => true,
            ];
        });
    }

    /**
     * Mark out_for_delivery home_delivery order as delivered. No ledger posts.
     *
     * @return array{order: PortalOrder, created: bool}
     */
    public function deliver(
        PortalOrder $order,
        int $storeId,
        string $idempotencyKey,
    ): array {
        $this->assertIdempotencyKey($idempotencyKey);

        if ((int) $order->store_id !== $storeId) {
            throw new RuntimeException('Order does not belong to this store.');
        }

        return DB::transaction(function () use ($order, $idempotencyKey): array {
            /** @var PortalOrder $locked */
            $locked = PortalOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->load('shipment');

            $this->assertPaidHomeDelivery($locked, 'marked delivered');

            if ($locked->status === PortalOrderStatuses::DELIVERED) {
                $storedKey = $locked->shipment?->deliver_idempotency_key;
                if ($storedKey === null || $storedKey === '') {
                    throw new RuntimeException('Order was already delivered; provide the original Idempotency-Key to replay.');
                }
                if ($storedKey !== $idempotencyKey) {
                    throw new RuntimeException('Idempotency-Key conflicts with a previous deliver for this order.');
                }

                return [
                    'order' => $locked->fresh(['store', 'items', 'customer', 'address', 'shipment']),
                    'created' => false,
                ];
            }

            if (PortalOrderStatuses::isTerminal((string) $locked->status)) {
                throw new RuntimeException('Cancelled or terminal orders cannot be marked delivered.');
            }

            if ($locked->status !== PortalOrderStatuses::OUT_FOR_DELIVERY) {
                throw new RuntimeException('Only out_for_delivery orders can be marked delivered.');
            }

            $shipment = $locked->shipment;
            if ($shipment === null) {
                throw new RuntimeException('Shipment record is missing for this order.');
            }

            $shipment->delivered_at = now();
            $shipment->deliver_idempotency_key = $idempotencyKey;
            $shipment->save();

            $locked->status = PortalOrderStatuses::DELIVERED;
            $locked->save();

            return [
                'order' => $locked->fresh(['store', 'items', 'customer', 'address', 'shipment']),
                'created' => true,
            ];
        });
    }

    /**
     * Pickup / click & collect: processing → ready_for_pickup. No ledger posts; no shipment.
     *
     * @return array{order: PortalOrder, created: bool}
     */
    public function markReadyForPickup(
        PortalOrder $order,
        int $storeId,
        string $idempotencyKey,
    ): array {
        $this->assertIdempotencyKey($idempotencyKey);

        if ((int) $order->store_id !== $storeId) {
            throw new RuntimeException('Order does not belong to this store.');
        }

        return DB::transaction(function () use ($order, $idempotencyKey): array {
            /** @var PortalOrder $locked */
            $locked = PortalOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPaidPickupMode($locked, 'marked ready for pickup');

            if ($locked->status === PortalOrderStatuses::READY_FOR_PICKUP) {
                $storedKey = $locked->ready_idempotency_key;
                if ($storedKey === null || $storedKey === '') {
                    throw new RuntimeException('Order was already marked ready for pickup; provide the original Idempotency-Key to replay.');
                }
                if ($storedKey !== $idempotencyKey) {
                    throw new RuntimeException('Idempotency-Key conflicts with a previous ready-for-pickup for this order.');
                }

                return [
                    'order' => $locked->fresh(['store', 'items', 'customer', 'address', 'shipment']),
                    'created' => false,
                ];
            }

            if (PortalOrderStatuses::isTerminal((string) $locked->status)) {
                throw new RuntimeException('Cancelled or terminal orders cannot be marked ready for pickup.');
            }

            if ($locked->status !== PortalOrderStatuses::PROCESSING) {
                throw new RuntimeException('Only packed (processing) store_pickup or click_collect orders can be marked ready for pickup.');
            }

            $locked->status = PortalOrderStatuses::READY_FOR_PICKUP;
            $locked->ready_idempotency_key = $idempotencyKey;
            $locked->save();

            return [
                'order' => $locked->fresh(['store', 'items', 'customer', 'address', 'shipment']),
                'created' => true,
            ];
        });
    }

    /**
     * Pickup / click & collect: ready_for_pickup → delivered (collected). No ledger posts.
     *
     * @return array{order: PortalOrder, created: bool}
     */
    public function markCollected(
        PortalOrder $order,
        int $storeId,
        string $idempotencyKey,
    ): array {
        $this->assertIdempotencyKey($idempotencyKey);

        if ((int) $order->store_id !== $storeId) {
            throw new RuntimeException('Order does not belong to this store.');
        }

        return DB::transaction(function () use ($order, $idempotencyKey): array {
            /** @var PortalOrder $locked */
            $locked = PortalOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPaidPickupMode($locked, 'marked collected');

            if ($locked->status === PortalOrderStatuses::DELIVERED) {
                $storedKey = $locked->collect_idempotency_key;
                if ($storedKey === null || $storedKey === '') {
                    throw new RuntimeException('Order was already collected; provide the original Idempotency-Key to replay.');
                }
                if ($storedKey !== $idempotencyKey) {
                    throw new RuntimeException('Idempotency-Key conflicts with a previous collect for this order.');
                }

                return [
                    'order' => $locked->fresh(['store', 'items', 'customer', 'address', 'shipment']),
                    'created' => false,
                ];
            }

            if (PortalOrderStatuses::isTerminal((string) $locked->status)) {
                throw new RuntimeException('Cancelled or terminal orders cannot be marked collected.');
            }

            if ($locked->status !== PortalOrderStatuses::READY_FOR_PICKUP) {
                throw new RuntimeException('Only ready_for_pickup orders can be marked collected.');
            }

            $locked->status = PortalOrderStatuses::DELIVERED;
            $locked->collect_idempotency_key = $idempotencyKey;
            $locked->collected_at = now();
            $locked->save();

            return [
                'order' => $locked->fresh(['store', 'items', 'customer', 'address', 'shipment']),
                'created' => true,
            ];
        });
    }

    public function isPackable(PortalOrder $order): bool
    {
        return $order->payment_status === 'paid'
            && $order->status === PortalOrderStatuses::CONFIRMED
            && $order->items->isNotEmpty();
    }

    public function isShippable(PortalOrder $order): bool
    {
        return $order->payment_status === 'paid'
            && $order->fulfilment_mode === 'home_delivery'
            && $order->status === PortalOrderStatuses::PROCESSING;
    }

    public function isDeliverable(PortalOrder $order): bool
    {
        $order->loadMissing('shipment');

        return $order->payment_status === 'paid'
            && $order->fulfilment_mode === 'home_delivery'
            && $order->status === PortalOrderStatuses::OUT_FOR_DELIVERY
            && $order->shipment !== null;
    }

    public function isReadyForPickup(PortalOrder $order): bool
    {
        return $order->payment_status === 'paid'
            && in_array((string) $order->fulfilment_mode, self::PICKUP_MODES, true)
            && $order->status === PortalOrderStatuses::PROCESSING;
    }

    public function isCollectable(PortalOrder $order): bool
    {
        return $order->payment_status === 'paid'
            && in_array((string) $order->fulfilment_mode, self::PICKUP_MODES, true)
            && $order->status === PortalOrderStatuses::READY_FOR_PICKUP;
    }

    private function assertPaidHomeDelivery(PortalOrder $order, string $action): void
    {
        if ($order->payment_status !== 'paid') {
            throw new RuntimeException("Only paid orders can be {$action}.");
        }

        if ($order->fulfilment_mode !== 'home_delivery') {
            throw new RuntimeException("Only home_delivery orders can be {$action}.");
        }
    }

    private function assertPaidPickupMode(PortalOrder $order, string $action): void
    {
        if ($order->payment_status !== 'paid') {
            throw new RuntimeException("Only paid orders can be {$action}.");
        }

        if (! in_array((string) $order->fulfilment_mode, self::PICKUP_MODES, true)) {
            throw new RuntimeException("Only store_pickup or click_collect orders can be {$action}.");
        }
    }

    private function staffStatusLabel(PortalOrder $order): string
    {
        if ($order->status === PortalOrderStatuses::DELIVERED
            && in_array((string) $order->fulfilment_mode, self::PICKUP_MODES, true)) {
            return 'Collected';
        }

        return PortalOrderStatuses::label((string) $order->status);
    }

    /**
     * @return array{carrierName: string|null, trackingNumber: string|null, shippedAt: string|null, deliveredAt: string|null}|null
     */
    private function shipmentSummary(?PortalShipment $shipment): ?array
    {
        if ($shipment === null) {
            return null;
        }

        return [
            'carrierName' => $shipment->carrier_name,
            'trackingNumber' => $shipment->tracking_number,
            'shippedAt' => $shipment->shipped_at?->toIso8601String(),
            'deliveredAt' => $shipment->delivered_at?->toIso8601String(),
        ];
    }

    private function assertIdempotencyKey(string $idempotencyKey): void
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw ValidationException::withMessages([
                'idempotencyKey' => ['Idempotency-Key must be between 1 and 64 characters.'],
            ]);
        }
    }

    private function nullableTrimmed(mixed $value, int $max, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([
                $field => ['Must be a string.'],
            ]);
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (strlen($trimmed) > $max) {
            throw ValidationException::withMessages([
                $field => ["Must be at most {$max} characters."],
            ]);
        }

        return $trimmed;
    }
}
