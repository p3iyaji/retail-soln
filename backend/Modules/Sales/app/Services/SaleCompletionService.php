<?php

namespace Modules\Sales\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerService;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Pricing\Services\TaxService;
use Modules\Products\Models\ProductVariant;
use Modules\Sales\Enums\SaleStatus;
use Modules\Sales\Jobs\SendSaleReceipt;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\Sale;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class SaleCompletionService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly TaxService $tax,
        private readonly CustomerService $customers,
    ) {}

    /**
     * @param  array{
     *   organizationId: int,
     *   storeId: int,
     *   userId: int,
     *   uuid: string,
     *   customerId?: ?int,
     *   idempotencyKey?: ?string,
     *   items: list<array{variantId: int, qty: string, unitPrice: string, discount: string}>,
     *   payments: list<array{method: string, amount: string, provider: string, reference?: ?string}>,
     * }  $input
     * @return array{sale: Sale, created: bool}
     */
    public function complete(array $input): array
    {
        $idempotencyKey = $input['idempotencyKey'] ?? null;
        if ($idempotencyKey !== null && $idempotencyKey !== '' && strlen($idempotencyKey) > 64) {
            throw new RuntimeException('Idempotency-Key must be at most 64 characters.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = Sale::query()
                ->where('organization_id', $input['organizationId'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertIdempotentPayloadMatches($existing, $input);

                return [
                    'sale' => $this->loadSale($existing),
                    'created' => false,
                ];
            }
        }

        if ($input['items'] === []) {
            throw new RuntimeException('Sale requires at least one item.');
        }

        if ($input['payments'] === []) {
            throw new RuntimeException('Sale requires at least one payment.');
        }

        $store = Store::query()
            ->where('organization_id', $input['organizationId'])
            ->whereKey($input['storeId'])
            ->first();

        if ($store === null) {
            throw new RuntimeException('Store not found.');
        }

        $customer = $this->resolveCustomer($input['organizationId'], $input['customerId'] ?? null);
        $computed = $this->computeSale($store, $input['items']);

        $paymentTotal = '0.0000';
        foreach ($input['payments'] as $payment) {
            $paymentTotal = bcadd($paymentTotal, bcadd((string) $payment['amount'], '0', 4), 4);
        }

        if (bccomp($paymentTotal, $computed['total'], 4) !== 0) {
            throw new RuntimeException('Payment total must equal sale total.');
        }

        try {
            $result = DB::transaction(function () use ($input, $idempotencyKey, $store, $customer, $computed) {
                if ($idempotencyKey !== null && $idempotencyKey !== '') {
                    $existing = Sale::query()
                        ->where('organization_id', $input['organizationId'])
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        $this->assertIdempotentPayloadMatches($existing, $input);

                        return [
                            'sale' => $this->loadSale($existing),
                            'created' => false,
                        ];
                    }
                }

                foreach ($computed['qtyByVariant'] as $variantId => $needed) {
                    $balance = $this->ledger->getLockedBalance(
                        $input['organizationId'],
                        LocationType::Store,
                        $store->id,
                        (int) $variantId,
                    );

                    if (bccomp((string) $balance->available, $needed, 4) < 0) {
                        throw new RuntimeException('Insufficient available stock for sale.');
                    }
                }

                try {
                    $sale = Sale::query()->create([
                        'organization_id' => $input['organizationId'],
                        'store_id' => $store->id,
                        'uuid' => $input['uuid'],
                        'customer_id' => $customer->id,
                        'cashier_id' => $input['userId'],
                        'subtotal' => $computed['subtotal'],
                        'discount_total' => $computed['discount_total'],
                        'tax_total' => $computed['tax_total'],
                        'total' => $computed['total'],
                        'status' => SaleStatus::Completed,
                        'idempotency_key' => ($idempotencyKey !== null && $idempotencyKey !== '') ? $idempotencyKey : null,
                    ]);
                } catch (QueryException $e) {
                    if ($this->isUniqueIdempotencyViolation($e) && $idempotencyKey !== null && $idempotencyKey !== '') {
                        $existing = Sale::query()
                            ->where('organization_id', $input['organizationId'])
                            ->where('idempotency_key', $idempotencyKey)
                            ->first();

                        if ($existing !== null) {
                            $this->assertIdempotentPayloadMatches($existing, $input);

                            return [
                                'sale' => $this->loadSale($existing),
                                'created' => false,
                            ];
                        }
                    }

                    throw $e;
                }

                $lineIndex = 0;
                foreach ($computed['lines'] as $line) {
                    $movementUuid = null;
                    if ($idempotencyKey !== null && $idempotencyKey !== '') {
                        $movementUuid = Uuid::uuid5(
                            Uuid::NAMESPACE_URL,
                            'sale:'.$idempotencyKey.':'.$lineIndex,
                        )->toString();
                    }

                    $negQty = bcmul($line['qty'], '-1', 4);

                    $movement = $this->ledger->post(new PostMovementData(
                        organizationId: $input['organizationId'],
                        locationType: LocationType::Store,
                        locationId: $store->id,
                        productVariantId: $line['variant_id'],
                        type: MovementType::Sale,
                        quantity: $negQty,
                        referenceType: 'sale',
                        referenceId: $sale->id,
                        userId: $input['userId'],
                        notes: 'Sale #'.$sale->id,
                        uuid: $movementUuid,
                    ));

                    $sale->items()->create([
                        'organization_id' => $input['organizationId'],
                        'product_variant_id' => $line['variant_id'],
                        'qty' => $line['qty'],
                        'unit_price' => $line['unit_price'],
                        'discount' => $line['discount'],
                        'tax_amount' => $line['tax_amount'],
                        'line_total' => $line['line_total'],
                        'inventory_movement_id' => $movement->id,
                    ]);

                    $lineIndex++;
                }

                foreach ($input['payments'] as $payment) {
                    $sale->payments()->create([
                        'organization_id' => $input['organizationId'],
                        'method' => $payment['method'],
                        'provider' => $payment['provider'],
                        'amount' => bcadd((string) $payment['amount'], '0', 4),
                        'reference' => $payment['reference'] ?? null,
                    ]);
                }

                $invoice = Invoice::query()->create([
                    'organization_id' => $input['organizationId'],
                    'sale_id' => $sale->id,
                    'invoice_number' => 'INV-'.$sale->id,
                    'status' => 'issued',
                    'subtotal' => $computed['subtotal'],
                    'tax_total' => $computed['tax_total'],
                    'total' => $computed['total'],
                    'issued_at' => now(),
                ]);

                foreach ($computed['lines'] as $line) {
                    $invoice->items()->create([
                        'organization_id' => $input['organizationId'],
                        'product_variant_id' => $line['variant_id'],
                        'qty' => $line['qty'],
                        'unit_price' => $line['unit_price'],
                        'discount' => $line['discount'],
                        'tax_amount' => $line['tax_amount'],
                        'line_total' => $line['line_total'],
                    ]);
                }

                return [
                    'sale' => $this->loadSale($sale->fresh()),
                    'created' => true,
                ];
            });
        } catch (QueryException $e) {
            if ($this->isUniqueIdempotencyViolation($e) && $idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = Sale::query()
                    ->where('organization_id', $input['organizationId'])
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    $this->assertIdempotentPayloadMatches($existing, $input);

                    return [
                        'sale' => $this->loadSale($existing),
                        'created' => false,
                    ];
                }
            }

            throw $e;
        }

        if ($result['created']) {
            SendSaleReceipt::dispatch($result['sale']->id);
        }

        return $result;
    }

    private function resolveCustomer(int $organizationId, ?int $customerId): Customer
    {
        if ($customerId === null) {
            return $this->customers->ensureWalkIn($organizationId);
        }

        $customer = Customer::query()
            ->where('organization_id', $organizationId)
            ->whereKey($customerId)
            ->first();

        if ($customer === null) {
            throw new RuntimeException('Customer not found.');
        }

        return $customer;
    }

    /**
     * @param  list<array{variantId: int, qty: string, unitPrice: string, discount: string}>  $items
     * @return array{
     *   lines: list<array{
     *     variant_id: int,
     *     qty: string,
     *     unit_price: string,
     *     discount: string,
     *     tax_amount: string,
     *     line_total: string,
     *     net: string,
     *   }>,
     *   qtyByVariant: array<int, string>,
     *   subtotal: string,
     *   discount_total: string,
     *   tax_total: string,
     *   total: string,
     * }
     */
    private function computeSale(Store $store, array $items): array
    {
        $lines = [];
        $qtyByVariant = [];
        $subtotal = '0.0000';
        $discountTotal = '0.0000';
        $taxTotal = '0.0000';
        $total = '0.0000';

        foreach ($items as $item) {
            $variant = ProductVariant::query()
                ->where('organization_id', $store->organization_id)
                ->whereKey($item['variantId'])
                ->first();

            if ($variant === null) {
                throw new RuntimeException('Variant not found.');
            }

            if (! $variant->is_active) {
                throw new RuntimeException('Variant is inactive.');
            }

            $qty = bcadd((string) $item['qty'], '0', 4);
            $unitPrice = bcadd((string) $item['unitPrice'], '0', 4);
            $discount = bcadd((string) $item['discount'], '0', 4);

            if (bccomp($qty, '0', 4) <= 0) {
                throw new RuntimeException('Quantity must be greater than zero.');
            }

            $lineNet = bcsub(bcmul($qty, $unitPrice, 8), $discount, 8);
            if (bccomp($lineNet, '0', 4) < 0) {
                $lineNet = '0.0000';
            }

            $taxResult = $this->tax->apply($store, $lineNet);

            $lines[] = [
                'variant_id' => $variant->id,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'tax_amount' => $taxResult['amount'],
                'line_total' => $taxResult['gross'],
                'net' => $taxResult['net'],
            ];

            $qtyByVariant[$variant->id] = bcadd($qtyByVariant[$variant->id] ?? '0.0000', $qty, 4);
            $subtotal = bcadd($subtotal, $taxResult['net'], 4);
            $discountTotal = bcadd($discountTotal, $discount, 4);
            $taxTotal = bcadd($taxTotal, $taxResult['amount'], 4);
            $total = bcadd($total, $taxResult['gross'], 4);
        }

        return [
            'lines' => $lines,
            'qtyByVariant' => $qtyByVariant,
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'total' => $total,
        ];
    }

    private function loadSale(Sale $sale): Sale
    {
        return $sale->load([
            'items.productVariant',
            'payments',
            'customer',
            'store',
            'invoice.items',
        ]);
    }

    /**
     * @param  array{
     *   uuid: string,
     *   storeId: int,
     *   customerId?: ?int,
     *   items: list<array{variantId: int, qty: string, unitPrice: string, discount: string}>,
     *   payments: list<array{method: string, amount: string, provider: string}>,
     * }  $input
     */
    private function assertIdempotentPayloadMatches(Sale $existing, array $input): void
    {
        if ($this->fingerprintFromSale($existing) !== $this->fingerprintFromInput($input)) {
            throw new IdempotencyConflictException(
                'Idempotency-Key was already used with a different request body.'
            );
        }
    }

    /**
     * @param  array{
     *   uuid: string,
     *   storeId: int,
     *   customerId?: ?int,
     *   items: list<array{variantId: int, qty: string, unitPrice: string, discount: string}>,
     *   payments: list<array{method: string, amount: string, provider: string}>,
     * }  $input
     */
    private function fingerprintFromInput(array $input): string
    {
        $normalized = [
            'uuid' => $input['uuid'],
            'storeId' => (int) $input['storeId'],
            'customerId' => $input['customerId'] ?? null,
            'items' => collect($input['items'])
                ->map(fn (array $item) => [
                    'variantId' => (int) $item['variantId'],
                    'qty' => bcadd((string) $item['qty'], '0', 4),
                    'unitPrice' => bcadd((string) $item['unitPrice'], '0', 4),
                    'discount' => bcadd((string) $item['discount'], '0', 4),
                ])
                ->sortBy('variantId')
                ->values()
                ->all(),
            'payments' => collect($input['payments'])
                ->map(fn (array $payment) => [
                    'method' => $payment['method'],
                    'amount' => bcadd((string) $payment['amount'], '0', 4),
                    'provider' => $payment['provider'],
                ])
                ->sortBy(fn (array $p) => $p['method'].$p['amount'])
                ->values()
                ->all(),
        ];

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function fingerprintFromSale(Sale $sale): string
    {
        $sale->loadMissing(['items', 'payments', 'customer']);

        $customerId = $sale->customer?->is_walk_in ? null : $sale->customer_id;

        return $this->fingerprintFromInput([
            'uuid' => $sale->uuid,
            'storeId' => $sale->store_id,
            'customerId' => $customerId,
            'items' => $sale->items->map(fn ($item) => [
                'variantId' => $item->product_variant_id,
                'qty' => (string) $item->qty,
                'unitPrice' => (string) $item->unit_price,
                'discount' => (string) $item->discount,
            ])->all(),
            'payments' => $sale->payments->map(fn ($payment) => [
                'method' => $payment->method,
                'amount' => (string) $payment->amount,
                'provider' => $payment->provider,
            ])->all(),
        ]);
    }

    private function isUniqueIdempotencyViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'idempotency_key')
            || str_contains($message, 'sales_organization_id_idempotency_key')
            || str_contains($message, 'sales_organization_id_uuid');
    }
}
