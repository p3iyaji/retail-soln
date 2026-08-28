<?php

namespace Modules\Sales\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Sales\Enums\SaleStatus;
use Modules\Sales\Models\Refund;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleItem;
use Modules\Sales\Models\SaleReturn;
use Modules\Sales\Models\SaleReturnItem;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class SaleReturnService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly CashierSessionService $sessions,
    ) {}

    /**
     * @return array<string, string>
     */
    public function returnableQuantities(Sale $sale): array
    {
        $sale->loadMissing('items');
        $returned = $this->returnedQtyBySaleItem($sale->id);

        $map = [];
        foreach ($sale->items as $item) {
            $sold = bcadd((string) $item->qty, '0', 4);
            $already = bcadd($returned[$item->id] ?? '0', '0', 4);
            $map[(string) $item->id] = bcsub($sold, $already, 4);
        }

        return $map;
    }

    /**
     * @param  array{
     *   organizationId: int,
     *   storeId: int,
     *   userId: int,
     *   uuid: string,
     *   saleId: int,
     *   sessionId?: ?int,
     *   reason: string,
     *   idempotencyKey?: ?string,
     *   items: list<array{saleItemId: int, qty: string}>,
     *   refunds: list<array{method: string, amount: string, provider: string, reference?: ?string}>,
     * }  $input
     * @return array{saleReturn: SaleReturn, created: bool}
     */
    public function create(array $input): array
    {
        $idempotencyKey = $input['idempotencyKey'] ?? null;
        if ($idempotencyKey !== null && $idempotencyKey !== '' && strlen($idempotencyKey) > 64) {
            throw new RuntimeException('Idempotency-Key must be at most 64 characters.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = SaleReturn::query()
                ->where('organization_id', $input['organizationId'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertIdempotentPayloadMatches($existing, $input);

                return [
                    'saleReturn' => $this->loadReturn($existing),
                    'created' => false,
                ];
            }
        }

        if ($input['items'] === []) {
            throw new RuntimeException('Return requires at least one item.');
        }

        if ($input['refunds'] === []) {
            throw new RuntimeException('Return requires at least one refund.');
        }

        $this->assertNoOpenInstallmentPlan($input['saleId']);

        try {
            return DB::transaction(function () use ($input, $idempotencyKey) {
                if ($idempotencyKey !== null && $idempotencyKey !== '') {
                    $existing = SaleReturn::query()
                        ->where('organization_id', $input['organizationId'])
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        $this->assertIdempotentPayloadMatches($existing, $input);

                        return [
                            'saleReturn' => $this->loadReturn($existing),
                            'created' => false,
                        ];
                    }
                }

                $sale = Sale::query()
                    ->with('items')
                    ->where('organization_id', $input['organizationId'])
                    ->whereKey($input['saleId'])
                    ->lockForUpdate()
                    ->first();

                if ($sale === null) {
                    throw new RuntimeException('Sale not found.');
                }

                if ($sale->status !== SaleStatus::Completed) {
                    throw new RuntimeException('Only completed sales can be returned.');
                }

                if ($sale->store_id !== $input['storeId']) {
                    throw new RuntimeException('Sale does not belong to this store.');
                }

                $session = null;
                if (($input['sessionId'] ?? null) !== null) {
                    $session = $this->sessions->assertOpenSessionForSale(
                        $input['organizationId'],
                        $input['storeId'],
                        $input['userId'],
                        (int) $input['sessionId'],
                    );
                }

                $saleItems = $sale->items->keyBy('id');
                $returnedQty = $this->returnedQtyBySaleItem($sale->id);
                $computedLines = [];
                $returnTotal = '0.0000';

                foreach ($input['items'] as $line) {
                    $saleItem = $saleItems->get($line['saleItemId']);

                    if ($saleItem === null) {
                        throw new RuntimeException('Sale item does not belong to this sale.');
                    }

                    $qty = bcadd((string) $line['qty'], '0', 4);

                    if (bccomp($qty, '0', 4) <= 0) {
                        throw new RuntimeException('Return quantity must be greater than zero.');
                    }

                    $soldQty = bcadd((string) $saleItem->qty, '0', 4);
                    $alreadyReturned = bcadd($returnedQty[$saleItem->id] ?? '0', '0', 4);
                    $returnable = bcsub($soldQty, $alreadyReturned, 4);

                    if (bccomp($qty, $returnable, 4) > 0) {
                        throw new RuntimeException('Return quantity exceeds returnable amount for a line item.');
                    }

                    $prorated = $this->prorateLine($saleItem, $qty);
                    $computedLines[] = [
                        'sale_item' => $saleItem,
                        'qty' => $qty,
                        ...$prorated,
                    ];
                    $returnTotal = bcadd($returnTotal, $prorated['line_total'], 4);
                }

                $refundTotal = '0.0000';
                foreach ($input['refunds'] as $refund) {
                    $refundTotal = bcadd($refundTotal, bcadd((string) $refund['amount'], '0', 4), 4);
                }

                if (bccomp($refundTotal, $returnTotal, 4) !== 0) {
                    throw new RuntimeException('Refund total must equal return total.');
                }

                try {
                    $saleReturn = SaleReturn::query()->create([
                        'organization_id' => $input['organizationId'],
                        'store_id' => $sale->store_id,
                        'sale_id' => $sale->id,
                        'uuid' => $input['uuid'],
                        'processed_by' => $input['userId'],
                        'session_id' => $session?->id,
                        'reason' => $input['reason'],
                        'total' => $returnTotal,
                        'idempotency_key' => ($idempotencyKey !== null && $idempotencyKey !== '') ? $idempotencyKey : null,
                    ]);
                } catch (QueryException $e) {
                    if ($this->isUniqueIdempotencyViolation($e) && $idempotencyKey !== null && $idempotencyKey !== '') {
                        $existing = SaleReturn::query()
                            ->where('organization_id', $input['organizationId'])
                            ->where('idempotency_key', $idempotencyKey)
                            ->first();

                        if ($existing !== null) {
                            $this->assertIdempotentPayloadMatches($existing, $input);

                            return [
                                'saleReturn' => $this->loadReturn($existing),
                                'created' => false,
                            ];
                        }
                    }

                    throw $e;
                }

                $lineIndex = 0;
                foreach ($computedLines as $line) {
                    /** @var SaleItem $saleItem */
                    $saleItem = $line['sale_item'];

                    $movementUuid = null;
                    if ($idempotencyKey !== null && $idempotencyKey !== '') {
                        $movementUuid = Uuid::uuid5(
                            Uuid::NAMESPACE_URL,
                            'sale-return:'.$idempotencyKey.':'.$lineIndex,
                        )->toString();
                    }

                    $movement = $this->ledger->post(new PostMovementData(
                        organizationId: $input['organizationId'],
                        locationType: LocationType::Store,
                        locationId: $sale->store_id,
                        productVariantId: $saleItem->product_variant_id,
                        type: MovementType::Return,
                        quantity: $line['qty'],
                        referenceType: 'sale_return',
                        referenceId: $saleReturn->id,
                        userId: $input['userId'],
                        notes: 'Return for sale #'.$sale->id,
                        uuid: $movementUuid,
                    ));

                    SaleReturnItem::query()->create([
                        'organization_id' => $input['organizationId'],
                        'sale_return_id' => $saleReturn->id,
                        'sale_item_id' => $saleItem->id,
                        'product_variant_id' => $saleItem->product_variant_id,
                        'qty' => $line['qty'],
                        'unit_price' => $line['unit_price'],
                        'discount' => $line['discount'],
                        'tax_amount' => $line['tax_amount'],
                        'line_total' => $line['line_total'],
                        'inventory_movement_id' => $movement->id,
                    ]);

                    $lineIndex++;
                }

                foreach ($input['refunds'] as $refund) {
                    Refund::query()->create([
                        'organization_id' => $input['organizationId'],
                        'sale_return_id' => $saleReturn->id,
                        'method' => $refund['method'],
                        'provider' => $refund['provider'],
                        'amount' => bcadd((string) $refund['amount'], '0', 4),
                        'reference' => $refund['reference'] ?? null,
                    ]);
                }

                $this->sessions->recordReturnCashRefunds($saleReturn->fresh(['refunds']), $input['userId']);

                return [
                    'saleReturn' => $this->loadReturn($saleReturn->fresh()),
                    'created' => true,
                ];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueIdempotencyViolation($e) && $idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = SaleReturn::query()
                    ->where('organization_id', $input['organizationId'])
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    $this->assertIdempotentPayloadMatches($existing, $input);

                    return [
                        'saleReturn' => $this->loadReturn($existing),
                        'created' => false,
                    ];
                }
            }

            throw $e;
        }
    }

    /**
     * @return array<int, string>
     */
    private function returnedQtyBySaleItem(int $saleId): array
    {
        $rows = SaleReturnItem::query()
            ->whereIn('sale_item_id', SaleItem::query()->where('sale_id', $saleId)->select('id'))
            ->get(['sale_item_id', 'qty']);

        $map = [];
        foreach ($rows as $row) {
            $id = (int) $row->sale_item_id;
            $map[$id] = bcadd($map[$id] ?? '0', (string) $row->qty, 4);
        }

        return $map;
    }

    /**
     * @return array{unit_price: string, discount: string, tax_amount: string, line_total: string}
     */
    private function prorateLine(SaleItem $item, string $returnQty): array
    {
        $soldQty = bcadd((string) $item->qty, '0', 4);
        $ratio = bcdiv($returnQty, $soldQty, 8);

        return [
            'unit_price' => bcadd((string) $item->unit_price, '0', 4),
            'discount' => bcmul(bcadd((string) $item->discount, '0', 4), $ratio, 4),
            'tax_amount' => bcmul(bcadd((string) $item->tax_amount, '0', 4), $ratio, 4),
            'line_total' => bcmul(bcadd((string) $item->line_total, '0', 4), $ratio, 4),
        ];
    }

    private function assertNoOpenInstallmentPlan(int $saleId): void
    {
        if (! Schema::hasTable('installment_plans')) {
            return;
        }

        $hasOpen = DB::table('installment_plans')
            ->where('sale_id', $saleId)
            ->whereIn('status', ['open', 'active', 'pending'])
            ->exists();

        if ($hasOpen) {
            throw new RuntimeException('Sale has an open installment plan; cancel or adjust it before refunding.');
        }
    }

    private function loadReturn(SaleReturn $saleReturn): SaleReturn
    {
        return $saleReturn->load([
            'items.productVariant',
            'refunds',
            'sale.items',
            'processedByUser',
        ]);
    }

    /**
     * @param  array{
     *   uuid: string,
     *   saleId: int,
     *   reason: string,
     *   items: list<array{saleItemId: int, qty: string}>,
     *   refunds: list<array{method: string, amount: string, provider: string}>,
     * }  $input
     */
    private function assertIdempotentPayloadMatches(SaleReturn $existing, array $input): void
    {
        if ($this->fingerprintFromReturn($existing) !== $this->fingerprintFromInput($input)) {
            throw new IdempotencyConflictException(
                'Idempotency-Key was already used with a different request body.'
            );
        }
    }

    /**
     * @param  array{
     *   uuid: string,
     *   saleId: int,
     *   reason: string,
     *   items: list<array{saleItemId: int, qty: string}>,
     *   refunds: list<array{method: string, amount: string, provider: string}>,
     * }  $input
     */
    private function fingerprintFromInput(array $input): string
    {
        $normalized = [
            'uuid' => $input['uuid'],
            'saleId' => (int) $input['saleId'],
            'reason' => $input['reason'],
            'items' => collect($input['items'])
                ->map(fn (array $item) => [
                    'saleItemId' => (int) $item['saleItemId'],
                    'qty' => bcadd((string) $item['qty'], '0', 4),
                ])
                ->sortBy('saleItemId')
                ->values()
                ->all(),
            'refunds' => collect($input['refunds'])
                ->map(fn (array $refund) => [
                    'method' => $refund['method'],
                    'amount' => bcadd((string) $refund['amount'], '0', 4),
                    'provider' => $refund['provider'],
                ])
                ->sortBy(fn (array $r) => $r['method'].$r['amount'])
                ->values()
                ->all(),
        ];

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function fingerprintFromReturn(SaleReturn $existing): string
    {
        $existing->loadMissing(['items', 'refunds']);

        return $this->fingerprintFromInput([
            'uuid' => $existing->uuid,
            'saleId' => $existing->sale_id,
            'reason' => $existing->reason?->value ?? (string) $existing->reason,
            'items' => $existing->items->map(fn ($item) => [
                'saleItemId' => $item->sale_item_id,
                'qty' => (string) $item->qty,
            ])->all(),
            'refunds' => $existing->refunds->map(fn ($refund) => [
                'method' => $refund->method,
                'amount' => (string) $refund->amount,
                'provider' => $refund->provider,
            ])->all(),
        ]);
    }

    private function isUniqueIdempotencyViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'idempotency_key')
            || str_contains($message, 'sale_returns_organization_id_idempotency_key')
            || str_contains($message, 'sale_returns_organization_id_uuid');
    }
}
