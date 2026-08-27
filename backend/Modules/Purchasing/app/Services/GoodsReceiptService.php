<?php

namespace Modules\Purchasing\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Purchasing\Enums\PurchaseOrderStatus;
use Modules\Purchasing\Models\GoodsReceipt;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\PurchaseOrderItem;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class GoodsReceiptService
{
    public function __construct(private readonly InventoryLedgerService $ledger) {}

    /**
     * @param  array{
     *   organizationId: int,
     *   purchaseOrderId: int,
     *   userId: int,
     *   notes?: ?string,
     *   idempotencyKey?: ?string,
     *   items: list<array{purchaseOrderItemId: int, qty: string}>
     * }  $input
     * @return array{receipt: GoodsReceipt, created: bool}
     */
    public function receive(array $input): array
    {
        $idempotencyKey = $input['idempotencyKey'] ?? null;
        if ($idempotencyKey !== null && $idempotencyKey !== '' && strlen($idempotencyKey) > 64) {
            throw new RuntimeException('Idempotency-Key must be at most 64 characters.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = GoodsReceipt::query()
                ->where('organization_id', $input['organizationId'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertIdempotentPayloadMatches($existing, $input);

                return [
                    'receipt' => $existing->load(['items.productVariant', 'purchaseOrder.items.productVariant', 'store']),
                    'created' => false,
                ];
            }
        }

        if ($input['items'] === []) {
            throw new RuntimeException('Goods receipt requires at least one item.');
        }

        $po = PurchaseOrder::query()
            ->where('organization_id', $input['organizationId'])
            ->whereKey($input['purchaseOrderId'])
            ->first();

        if ($po === null) {
            throw new RuntimeException('Purchase order not found.');
        }

        if (! $po->status->canReceive()) {
            throw new RuntimeException('Only approved or partially received purchase orders can be received.');
        }

        try {
            return DB::transaction(function () use ($input, $po, $idempotencyKey) {
                if ($idempotencyKey !== null && $idempotencyKey !== '') {
                    $existing = GoodsReceipt::query()
                        ->where('organization_id', $input['organizationId'])
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        $this->assertIdempotentPayloadMatches($existing, $input);

                        return [
                            'receipt' => $existing->load(['items.productVariant', 'purchaseOrder.items.productVariant', 'store']),
                            'created' => false,
                        ];
                    }
                }

                $po = PurchaseOrder::query()
                    ->whereKey($po->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($po->organization_id !== $input['organizationId']) {
                    throw new RuntimeException('Purchase order not found.');
                }

                if (! $po->status->canReceive()) {
                    throw new RuntimeException('Only approved or partially received purchase orders can be received.');
                }

                $poItems = PurchaseOrderItem::query()
                    ->where('purchase_order_id', $po->id)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                try {
                    $receipt = GoodsReceipt::query()->create([
                        'organization_id' => $input['organizationId'],
                        'purchase_order_id' => $po->id,
                        'store_id' => $po->store_id,
                        'received_by' => $input['userId'],
                        'notes' => $input['notes'] ?? null,
                        'idempotency_key' => ($idempotencyKey !== null && $idempotencyKey !== '') ? $idempotencyKey : null,
                    ]);
                } catch (QueryException $e) {
                    if ($this->isUniqueIdempotencyViolation($e) && $idempotencyKey !== null && $idempotencyKey !== '') {
                        $existing = GoodsReceipt::query()
                            ->where('organization_id', $input['organizationId'])
                            ->where('idempotency_key', $idempotencyKey)
                            ->first();

                        if ($existing !== null) {
                            $this->assertIdempotentPayloadMatches($existing, $input);

                            return [
                                'receipt' => $existing->load(['items.productVariant', 'purchaseOrder.items.productVariant', 'store']),
                                'created' => false,
                            ];
                        }
                    }

                    throw $e;
                }

                $lineIndex = 0;
                foreach ($input['items'] as $line) {
                    $poItem = $poItems->get($line['purchaseOrderItemId']);

                    if ($poItem === null) {
                        throw new RuntimeException('Purchase order item does not belong to this purchase order.');
                    }

                    if (! preg_match('/^\d+(\.\d{1,4})?$/', $line['qty']) || bccomp($line['qty'], '0', 4) <= 0) {
                        throw new RuntimeException('Received quantity must be a positive decimal.');
                    }

                    $remaining = bcsub((string) $poItem->qty, (string) $poItem->qty_received, 4);

                    if (bccomp($line['qty'], $remaining, 4) > 0) {
                        throw new RuntimeException('Received quantity exceeds remaining receivable quantity.');
                    }

                    $movementUuid = null;
                    if ($idempotencyKey !== null && $idempotencyKey !== '') {
                        $movementUuid = Uuid::uuid5(
                            Uuid::NAMESPACE_URL,
                            'goods-receipt:'.$idempotencyKey.':'.$lineIndex,
                        )->toString();
                    }

                    $movement = $this->ledger->post(new PostMovementData(
                        organizationId: $input['organizationId'],
                        locationType: LocationType::Store,
                        locationId: $po->store_id,
                        productVariantId: $poItem->product_variant_id,
                        type: MovementType::Purchase,
                        quantity: $line['qty'],
                        unitCost: (string) $poItem->unit_cost,
                        referenceType: 'goods_receipt',
                        referenceId: $receipt->id,
                        userId: $input['userId'],
                        notes: 'Goods receipt #'.$receipt->id,
                        uuid: $movementUuid,
                    ));

                    $receipt->items()->create([
                        'organization_id' => $input['organizationId'],
                        'purchase_order_item_id' => $poItem->id,
                        'product_variant_id' => $poItem->product_variant_id,
                        'qty' => $line['qty'],
                        'unit_cost' => $poItem->unit_cost,
                        'inventory_movement_id' => $movement->id,
                    ]);

                    $poItem->qty_received = bcadd((string) $poItem->qty_received, $line['qty'], 4);
                    $poItem->save();
                    $lineIndex++;
                }

                $allReceived = $poItems->every(
                    fn (PurchaseOrderItem $item) => bccomp((string) $item->qty_received, (string) $item->qty, 4) >= 0
                );

                $po->status = $allReceived ? PurchaseOrderStatus::Received : PurchaseOrderStatus::Partial;
                $po->save();

                return [
                    'receipt' => $receipt->fresh()->load(['items.productVariant', 'purchaseOrder.items.productVariant', 'store']),
                    'created' => true,
                ];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueIdempotencyViolation($e) && $idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = GoodsReceipt::query()
                    ->where('organization_id', $input['organizationId'])
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    $this->assertIdempotentPayloadMatches($existing, $input);

                    return [
                        'receipt' => $existing->load(['items.productVariant', 'purchaseOrder.items.productVariant', 'store']),
                        'created' => false,
                    ];
                }
            }

            throw $e;
        }
    }

    /**
     * @param  array{
     *   purchaseOrderId: int,
     *   notes?: ?string,
     *   items: list<array{purchaseOrderItemId: int, qty: string}>
     * }  $input
     */
    private function assertIdempotentPayloadMatches(GoodsReceipt $existing, array $input): void
    {
        if ($this->fingerprintFromReceipt($existing) !== $this->fingerprintFromInput($input)) {
            throw new IdempotencyConflictException(
                'Idempotency-Key was already used with a different request body.'
            );
        }
    }

    /**
     * @param  array{
     *   purchaseOrderId: int,
     *   notes?: ?string,
     *   items: list<array{purchaseOrderItemId: int, qty: string}>
     * }  $input
     */
    private function fingerprintFromInput(array $input): string
    {
        $normalized = [
            'purchaseOrderId' => (int) $input['purchaseOrderId'],
            'notes' => $input['notes'] ?? null,
            'items' => collect($input['items'])
                ->map(fn (array $item) => [
                    'purchaseOrderItemId' => (int) $item['purchaseOrderItemId'],
                    'qty' => bcadd((string) $item['qty'], '0', 4),
                ])
                ->sortBy('purchaseOrderItemId')
                ->values()
                ->all(),
        ];

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function fingerprintFromReceipt(GoodsReceipt $receipt): string
    {
        $receipt->loadMissing('items');

        return $this->fingerprintFromInput([
            'purchaseOrderId' => $receipt->purchase_order_id,
            'notes' => $receipt->notes,
            'items' => $receipt->items->map(fn ($item) => [
                'purchaseOrderItemId' => $item->purchase_order_item_id,
                'qty' => (string) $item->qty,
            ])->all(),
        ]);
    }

    private function isUniqueIdempotencyViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'idempotency_key')
            || str_contains($message, 'goods_receipts_organization_id_idempotency_key');
    }
}
