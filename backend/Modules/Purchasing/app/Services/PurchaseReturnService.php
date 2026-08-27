<?php

namespace Modules\Purchasing\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Purchasing\Models\GoodsReceipt;
use Modules\Purchasing\Models\GoodsReceiptItem;
use Modules\Purchasing\Models\PurchaseReturn;
use Modules\Purchasing\Models\PurchaseReturnItem;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class PurchaseReturnService
{
    public function __construct(private readonly InventoryLedgerService $ledger) {}

    /**
     * @param  array{
     *   organizationId: int,
     *   goodsReceiptId: int,
     *   userId: int,
     *   notes?: ?string,
     *   idempotencyKey?: ?string,
     *   items: list<array{goodsReceiptItemId: int, qty: string}>
     * }  $input
     * @return array{purchaseReturn: PurchaseReturn, created: bool}
     */
    public function create(array $input): array
    {
        $idempotencyKey = $input['idempotencyKey'] ?? null;
        if ($idempotencyKey !== null && $idempotencyKey !== '' && strlen($idempotencyKey) > 64) {
            throw new RuntimeException('Idempotency-Key must be at most 64 characters.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = PurchaseReturn::query()
                ->where('organization_id', $input['organizationId'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertIdempotentPayloadMatches($existing, $input);

                return [
                    'purchaseReturn' => $this->loadReturn($existing),
                    'created' => false,
                ];
            }
        }

        if ($input['items'] === []) {
            throw new RuntimeException('Purchase return requires at least one item.');
        }

        try {
            return DB::transaction(function () use ($input, $idempotencyKey) {
                if ($idempotencyKey !== null && $idempotencyKey !== '') {
                    $existing = PurchaseReturn::query()
                        ->where('organization_id', $input['organizationId'])
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        $this->assertIdempotentPayloadMatches($existing, $input);

                        return [
                            'purchaseReturn' => $this->loadReturn($existing),
                            'created' => false,
                        ];
                    }
                }

                $receipt = GoodsReceipt::query()
                    ->with('purchaseOrder')
                    ->where('organization_id', $input['organizationId'])
                    ->whereKey($input['goodsReceiptId'])
                    ->lockForUpdate()
                    ->first();

                if ($receipt === null) {
                    throw new RuntimeException('Goods receipt not found.');
                }

                if ($receipt->purchaseOrder === null) {
                    throw new RuntimeException('Purchase order missing for goods receipt.');
                }

                $grnItems = GoodsReceiptItem::query()
                    ->where('goods_receipt_id', $receipt->id)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $creditAmount = '0.0000';
                $normalizedLines = [];

                foreach ($input['items'] as $line) {
                    $grnItem = $grnItems->get($line['goodsReceiptItemId']);

                    if ($grnItem === null) {
                        throw new RuntimeException('Goods receipt item does not belong to this goods receipt.');
                    }

                    if (! preg_match('/^\d+(\.\d{1,4})?$/', $line['qty']) || bccomp($line['qty'], '0', 4) <= 0) {
                        throw new RuntimeException('Return quantity must be a positive decimal.');
                    }

                    $alreadyReturned = '0.0000';
                    foreach (
                        PurchaseReturnItem::query()
                            ->where('goods_receipt_item_id', $grnItem->id)
                            ->lockForUpdate()
                            ->pluck('qty') as $qty
                    ) {
                        $alreadyReturned = bcadd($alreadyReturned, bcadd((string) $qty, '0', 4), 4);
                    }

                    $returnable = bcsub(bcadd((string) $grnItem->qty, '0', 4), $alreadyReturned, 4);

                    if (bccomp($line['qty'], $returnable, 4) > 0) {
                        throw new RuntimeException('Return quantity exceeds remaining returnable quantity.');
                    }

                    $unitCost = bcadd((string) $grnItem->unit_cost, '0', 4);
                    $lineCredit = bcmul($line['qty'], $unitCost, 4);
                    $creditAmount = bcadd($creditAmount, $lineCredit, 4);

                    $normalizedLines[] = [
                        'grnItem' => $grnItem,
                        'qty' => bcadd($line['qty'], '0', 4),
                        'unitCost' => $unitCost,
                    ];
                }

                // Aggregate return qty by variant for stock checks within this request.
                $qtyByVariant = [];
                foreach ($normalizedLines as $normalized) {
                    $variantId = $normalized['grnItem']->product_variant_id;
                    $qtyByVariant[$variantId] = bcadd($qtyByVariant[$variantId] ?? '0.0000', $normalized['qty'], 4);
                }

                foreach ($qtyByVariant as $variantId => $needed) {
                    $balance = $this->ledger->getLockedBalance(
                        $input['organizationId'],
                        LocationType::Store,
                        $receipt->store_id,
                        (int) $variantId,
                    );

                    if (bccomp((string) $balance->available, $needed, 4) < 0) {
                        throw new RuntimeException('Insufficient available stock for purchase return.');
                    }
                }

                try {
                    $purchaseReturn = PurchaseReturn::query()->create([
                        'organization_id' => $input['organizationId'],
                        'goods_receipt_id' => $receipt->id,
                        'purchase_order_id' => $receipt->purchase_order_id,
                        'supplier_id' => $receipt->purchaseOrder->supplier_id,
                        'store_id' => $receipt->store_id,
                        'credit_amount' => $creditAmount,
                        'notes' => $input['notes'] ?? null,
                        'returned_by' => $input['userId'],
                        'idempotency_key' => ($idempotencyKey !== null && $idempotencyKey !== '') ? $idempotencyKey : null,
                    ]);
                } catch (QueryException $e) {
                    if ($this->isUniqueIdempotencyViolation($e) && $idempotencyKey !== null && $idempotencyKey !== '') {
                        $existing = PurchaseReturn::query()
                            ->where('organization_id', $input['organizationId'])
                            ->where('idempotency_key', $idempotencyKey)
                            ->first();

                        if ($existing !== null) {
                            $this->assertIdempotentPayloadMatches($existing, $input);

                            return [
                                'purchaseReturn' => $this->loadReturn($existing),
                                'created' => false,
                            ];
                        }
                    }

                    throw $e;
                }

                $lineIndex = 0;
                foreach ($normalizedLines as $normalized) {
                    /** @var GoodsReceiptItem $grnItem */
                    $grnItem = $normalized['grnItem'];

                    $movementUuid = null;
                    if ($idempotencyKey !== null && $idempotencyKey !== '') {
                        $movementUuid = Uuid::uuid5(
                            Uuid::NAMESPACE_URL,
                            'purchase-return:'.$idempotencyKey.':'.$lineIndex,
                        )->toString();
                    }

                    $negQty = bcmul($normalized['qty'], '-1', 4);

                    $movement = $this->ledger->post(new PostMovementData(
                        organizationId: $input['organizationId'],
                        locationType: LocationType::Store,
                        locationId: $receipt->store_id,
                        productVariantId: $grnItem->product_variant_id,
                        type: MovementType::PurchaseReturn,
                        quantity: $negQty,
                        unitCost: $normalized['unitCost'],
                        referenceType: 'purchase_return',
                        referenceId: $purchaseReturn->id,
                        userId: $input['userId'],
                        notes: 'Purchase return #'.$purchaseReturn->id,
                        uuid: $movementUuid,
                    ));

                    $purchaseReturn->items()->create([
                        'organization_id' => $input['organizationId'],
                        'goods_receipt_item_id' => $grnItem->id,
                        'purchase_order_item_id' => $grnItem->purchase_order_item_id,
                        'product_variant_id' => $grnItem->product_variant_id,
                        'qty' => $normalized['qty'],
                        'unit_cost' => $normalized['unitCost'],
                        'inventory_movement_id' => $movement->id,
                    ]);

                    $lineIndex++;
                }

                return [
                    'purchaseReturn' => $this->loadReturn($purchaseReturn->fresh()),
                    'created' => true,
                ];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueIdempotencyViolation($e) && $idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = PurchaseReturn::query()
                    ->where('organization_id', $input['organizationId'])
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    $this->assertIdempotentPayloadMatches($existing, $input);

                    return [
                        'purchaseReturn' => $this->loadReturn($existing),
                        'created' => false,
                    ];
                }
            }

            throw $e;
        }
    }

    private function loadReturn(PurchaseReturn $purchaseReturn): PurchaseReturn
    {
        return $purchaseReturn->load([
            'items.productVariant',
            'goodsReceipt',
            'purchaseOrder',
            'supplier',
            'store',
        ]);
    }

    /**
     * @param  array{
     *   goodsReceiptId: int,
     *   notes?: ?string,
     *   items: list<array{goodsReceiptItemId: int, qty: string}>
     * }  $input
     */
    private function assertIdempotentPayloadMatches(PurchaseReturn $existing, array $input): void
    {
        if ($this->fingerprintFromReturn($existing) !== $this->fingerprintFromInput($input)) {
            throw new IdempotencyConflictException(
                'Idempotency-Key was already used with a different request body.'
            );
        }
    }

    /**
     * @param  array{
     *   goodsReceiptId: int,
     *   notes?: ?string,
     *   items: list<array{goodsReceiptItemId: int, qty: string}>
     * }  $input
     */
    private function fingerprintFromInput(array $input): string
    {
        $normalized = [
            'goodsReceiptId' => (int) $input['goodsReceiptId'],
            'notes' => $input['notes'] ?? null,
            'items' => collect($input['items'])
                ->map(fn (array $item) => [
                    'goodsReceiptItemId' => (int) $item['goodsReceiptItemId'],
                    'qty' => bcadd((string) $item['qty'], '0', 4),
                ])
                ->sortBy('goodsReceiptItemId')
                ->values()
                ->all(),
        ];

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function fingerprintFromReturn(PurchaseReturn $purchaseReturn): string
    {
        $purchaseReturn->loadMissing('items');

        return $this->fingerprintFromInput([
            'goodsReceiptId' => $purchaseReturn->goods_receipt_id,
            'notes' => $purchaseReturn->notes,
            'items' => $purchaseReturn->items->map(fn ($item) => [
                'goodsReceiptItemId' => $item->goods_receipt_item_id,
                'qty' => (string) $item->qty,
            ])->all(),
        ]);
    }

    private function isUniqueIdempotencyViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'idempotency_key')
            || str_contains($message, 'purchase_returns_organization_id_idempotency_key');
    }
}
