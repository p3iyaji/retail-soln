<?php

namespace Modules\Sales\Services;

use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerService;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Sales\Enums\InstallmentPlanStatus;
use Modules\Sales\Enums\InstallmentStatus;
use Modules\Sales\Enums\SaleStatus;
use Modules\Sales\Jobs\SendSaleReceipt;
use Modules\Sales\Models\Installment;
use Modules\Sales\Models\InstallmentPlan;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\Sale;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class SaleCompletionService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly CustomerService $customers,
        private readonly SaleLineCalculator $lineCalculator,
        private readonly CashierSessionService $sessions,
        private readonly InstallmentPlanService $installmentPlans,
    ) {}

    /**
     * @param  array{
     *   organizationId: int,
     *   storeId: int,
     *   userId: int,
     *   uuid: string,
     *   customerId?: ?int,
     *   idempotencyKey?: ?string,
     *   sessionId: int,
     *   terminalId?: ?int,
     *   items: list<array{variantId: int, qty: string, unitPrice: string, discount: string}>,
     *   payments: list<array{method: string, amount: string, provider: string, reference?: ?string}>,
     *   installmentPlan?: ?array{
     *     mode: string,
     *     installments: list<array{sequence: int, amount: string, dueAt: string}>,
     *   },
     *   allowOversell?: bool,
     * }  $input
     * @return array{
     *   sale: Sale,
     *   created: bool,
     *   oversells: list<array{variantId: int, qtyDeficit: string}>,
     * }
     */
    public function complete(array $input): array
    {
        $allowOversell = (bool) ($input['allowOversell'] ?? false);
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
                    'oversells' => [],
                ];
            }
        }

        if ($input['items'] === []) {
            throw new RuntimeException('Sale requires at least one item.');
        }

        if ($input['payments'] === []) {
            throw new RuntimeException('Sale requires at least one payment.');
        }

        $session = $this->sessions->assertOpenSessionForSale(
            $input['organizationId'],
            $input['storeId'],
            $input['userId'],
            $input['sessionId'],
        );

        $store = Store::query()
            ->where('organization_id', $input['organizationId'])
            ->whereKey($input['storeId'])
            ->first();

        if ($store === null) {
            throw new RuntimeException('Store not found.');
        }

        $customer = $this->resolveCustomer($input['organizationId'], $input['customerId'] ?? null);
        $computed = $this->lineCalculator->compute($store, $input['items']);
        $installmentPlanInput = $input['installmentPlan'] ?? null;

        $paymentTotal = '0.0000';
        foreach ($input['payments'] as $payment) {
            $paymentTotal = bcadd($paymentTotal, bcadd((string) $payment['amount'], '0', 4), 4);
        }

        $planData = null;
        if ($installmentPlanInput !== null) {
            if ($input['customerId'] === null) {
                throw new RuntimeException('Installment plans require a registered customer.');
            }
            $planData = $this->installmentPlans->validateForSale(
                $customer,
                $computed['total'],
                $paymentTotal,
                $installmentPlanInput,
            );
        } elseif (! Money::equalsAtCurrency($paymentTotal, $computed['total'])) {
            throw new RuntimeException('Payment total must equal sale total.');
        }

        try {
            $result = DB::transaction(function () use ($input, $idempotencyKey, $store, $customer, $computed, $session, $planData, $paymentTotal, $allowOversell) {
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
                            'oversells' => [],
                        ];
                    }
                }

                $oversells = [];
                foreach ($computed['qtyByVariant'] as $variantId => $needed) {
                    $balance = $this->ledger->getLockedBalance(
                        $input['organizationId'],
                        LocationType::Store,
                        $store->id,
                        (int) $variantId,
                    );

                    $available = (string) $balance->available;
                    if (bccomp($available, $needed, 4) < 0) {
                        if (! $allowOversell) {
                            throw new RuntimeException('Insufficient available stock for sale.');
                        }

                        $deficit = bcsub($needed, $available, 4);
                        if (bccomp($deficit, '0', 4) > 0) {
                            $oversells[] = [
                                'variantId' => (int) $variantId,
                                'qtyDeficit' => $deficit,
                            ];
                        }
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
                        'terminal_id' => $input['terminalId'] ?? $session->terminal_id,
                        'session_id' => $session->id,
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
                                'oversells' => [],
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

                $this->sessions->recordSaleCashPayments($sale->fresh(['payments']), $input['userId']);

                if ($planData !== null) {
                    $lockedCustomer = Customer::query()
                        ->where('organization_id', $input['organizationId'])
                        ->whereKey($customer->id)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedCustomer === null) {
                        throw new RuntimeException('Customer not found.');
                    }

                    $this->installmentPlans->validateForSale(
                        $lockedCustomer,
                        $computed['total'],
                        $paymentTotal,
                        $input['installmentPlan'] ?? [],
                    );

                    $plan = InstallmentPlan::query()->create([
                        'organization_id' => $input['organizationId'],
                        'store_id' => $store->id,
                        'customer_id' => $lockedCustomer->id,
                        'sale_id' => $sale->id,
                        'invoice_id' => $invoice->id,
                        'principal' => $planData['principal'],
                        'fee_total' => $planData['fee_total'],
                        'mode' => $planData['mode'],
                        'status' => InstallmentPlanStatus::Open,
                    ]);

                    foreach ($planData['installments'] as $line) {
                        Installment::query()->create([
                            'organization_id' => $input['organizationId'],
                            'installment_plan_id' => $plan->id,
                            'sequence' => $line['sequence'],
                            'amount' => $line['amount'],
                            'due_at' => $line['due_at'],
                            'status' => InstallmentStatus::Pending,
                        ]);
                    }

                    $lockedCustomer->balance = bcadd((string) $lockedCustomer->balance, $planData['principal'], 4);
                    $lockedCustomer->save();
                }

                return [
                    'sale' => $this->loadSale($sale->fresh()),
                    'created' => true,
                    'oversells' => $oversells,
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
                        'oversells' => [],
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

    private function loadSale(Sale $sale): Sale
    {
        return $sale->load([
            'items.productVariant',
            'payments',
            'customer',
            'store',
            'invoice.items',
            'installmentPlan.installments',
        ]);
    }

    /**
     * @param  array{
     *   uuid: string,
     *   storeId: int,
     *   customerId?: ?int,
     *   items: list<array{variantId: int, qty: string, unitPrice: string, discount: string}>,
     *   payments: list<array{method: string, amount: string, provider: string}>,
     *   installmentPlan?: ?array{
     *     mode: string,
     *     installments: list<array{sequence: int, amount: string, dueAt: string}>,
     *   },
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
     *   installmentPlan?: ?array{
     *     mode: string,
     *     installments: list<array{sequence: int, amount: string, dueAt: string}>,
     *   },
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
            'installmentPlan' => $this->normalizeInstallmentPlanFingerprint($input['installmentPlan'] ?? null),
        ];

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  ?array{
     *   mode: string,
     *   installments: list<array{sequence: int, amount: string, dueAt: string}>,
     * }  $plan
     * @return ?array<string, mixed>
     */
    private function normalizeInstallmentPlanFingerprint(?array $plan): ?array
    {
        if ($plan === null) {
            return null;
        }

        return [
            'mode' => $plan['mode'],
            'installments' => collect($plan['installments'])
                ->map(fn (array $row) => [
                    'sequence' => (int) $row['sequence'],
                    'amount' => bcadd((string) $row['amount'], '0', 4),
                    'dueAt' => $row['dueAt'],
                ])
                ->sortBy('sequence')
                ->values()
                ->all(),
        ];
    }

    private function fingerprintFromSale(Sale $sale): string
    {
        $sale->loadMissing(['items', 'payments', 'customer', 'installmentPlan.installments']);

        $customerId = $sale->customer?->is_walk_in ? null : $sale->customer_id;

        $installmentPlan = null;
        if ($sale->installmentPlan !== null) {
            $installmentPlan = [
                'mode' => $sale->installmentPlan->mode->value ?? $sale->installmentPlan->mode,
                'installments' => $sale->installmentPlan->installments->map(fn (Installment $row) => [
                    'sequence' => $row->sequence,
                    'amount' => (string) $row->amount,
                    'dueAt' => $row->due_at?->toDateString(),
                ])->all(),
            ];
        }

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
            'installmentPlan' => $installmentPlan,
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
