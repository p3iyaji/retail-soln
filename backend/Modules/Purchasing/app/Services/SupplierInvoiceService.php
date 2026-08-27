<?php

namespace Modules\Purchasing\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Purchasing\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\Models\GoodsReceipt;
use Modules\Purchasing\Models\SupplierInvoice;
use RuntimeException;

class SupplierInvoiceService
{
    /**
     * DECIMAL(15,4): up to 11 digits before the decimal point.
     */
    public const MONEY_PATTERN = '/^\d{1,11}(\.\d{1,4})?$/';

    /**
     * @param  array{
     *   organizationId: int,
     *   goodsReceiptId: int,
     *   userId: int,
     *   totalAmount?: ?string,
     *   notes?: ?string,
     *   idempotencyKey?: ?string,
     * }  $input
     * @return array{invoice: SupplierInvoice, created: bool}
     */
    public function create(array $input): array
    {
        $idempotencyKey = $input['idempotencyKey'] ?? null;
        if ($idempotencyKey !== null && $idempotencyKey !== '' && strlen($idempotencyKey) > 64) {
            throw new RuntimeException('Idempotency-Key must be at most 64 characters.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = SupplierInvoice::query()
                ->where('organization_id', $input['organizationId'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertIdempotentPayloadMatches($existing, $input);

                return [
                    'invoice' => $existing->load(['payments', 'supplier', 'purchaseOrder', 'goodsReceipt.items']),
                    'created' => false,
                ];
            }
        }

        return DB::transaction(function () use ($input, $idempotencyKey) {
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = SupplierInvoice::query()
                    ->where('organization_id', $input['organizationId'])
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $this->assertIdempotentPayloadMatches($existing, $input);

                    return [
                        'invoice' => $existing->load(['payments', 'supplier', 'purchaseOrder', 'goodsReceipt.items']),
                        'created' => false,
                    ];
                }
            }

            $receipt = GoodsReceipt::query()
                ->with(['items', 'purchaseOrder'])
                ->where('organization_id', $input['organizationId'])
                ->whereKey($input['goodsReceiptId'])
                ->lockForUpdate()
                ->first();

            if ($receipt === null) {
                throw new RuntimeException('Goods receipt not found.');
            }

            $po = $receipt->purchaseOrder;
            if ($po === null || $po->organization_id !== $input['organizationId']) {
                throw new RuntimeException('Goods receipt purchase order not found.');
            }

            $alreadyInvoiced = SupplierInvoice::query()
                ->where('organization_id', $input['organizationId'])
                ->where('goods_receipt_id', $receipt->id)
                ->exists();

            if ($alreadyInvoiced) {
                throw new RuntimeException('Goods receipt already has a supplier invoice.');
            }

            $suggested = $this->suggestedAmountFromReceipt($receipt);
            $totalAmount = $input['totalAmount'] ?? null;
            if ($totalAmount === null || $totalAmount === '') {
                $totalAmount = $suggested;
            }

            $this->assertPositiveMoneyDecimal($totalAmount, 'Invoice total');

            try {
                $invoice = $this->createWithUniqueNumber([
                    'organization_id' => $input['organizationId'],
                    'supplier_id' => $po->supplier_id,
                    'purchase_order_id' => $po->id,
                    'goods_receipt_id' => $receipt->id,
                    'status' => SupplierInvoiceStatus::Open,
                    'total_amount' => $totalAmount,
                    'notes' => $input['notes'] ?? null,
                    'created_by' => $input['userId'],
                    'idempotency_key' => ($idempotencyKey !== null && $idempotencyKey !== '') ? $idempotencyKey : null,
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueIdempotencyViolation($e) && $idempotencyKey !== null && $idempotencyKey !== '') {
                    $existing = SupplierInvoice::query()
                        ->where('organization_id', $input['organizationId'])
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existing !== null) {
                        $this->assertIdempotentPayloadMatches($existing, $input);

                        return [
                            'invoice' => $existing->load(['payments', 'supplier', 'purchaseOrder', 'goodsReceipt.items']),
                            'created' => false,
                        ];
                    }
                }

                if ($this->isUniqueGoodsReceiptViolation($e)) {
                    throw new RuntimeException('Goods receipt already has a supplier invoice.');
                }

                throw $e;
            }

            // Optional AD-12 hook: Accounting journals deferred — no journal posting in 3.4.

            return [
                'invoice' => $invoice->load(['payments', 'supplier', 'purchaseOrder', 'goodsReceipt.items']),
                'created' => true,
            ];
        });
    }

    public function suggestedAmountFromReceipt(GoodsReceipt $receipt): string
    {
        $total = '0.0000';
        foreach ($receipt->items as $item) {
            $line = bcmul((string) $item->qty, (string) $item->unit_cost, 4);
            $total = bcadd($total, $line, 4);
        }

        return $total;
    }

    public function refreshStatus(SupplierInvoice $invoice): SupplierInvoice
    {
        $paid = $invoice->amountPaid();
        $total = (string) $invoice->total_amount;

        if (bccomp($total, '0', 4) <= 0 || bccomp($paid, $total, 4) >= 0) {
            $invoice->status = SupplierInvoiceStatus::Paid;
        } elseif (bccomp($paid, '0', 4) <= 0) {
            $invoice->status = SupplierInvoiceStatus::Open;
        } else {
            $invoice->status = SupplierInvoiceStatus::Partial;
        }

        $invoice->save();

        return $invoice;
    }

    /**
     * @return list<array{id: int, purchaseOrderId: int, purchaseOrderNumber: string|null, suggestedAmount: string, createdAt: string|null}>
     */
    public function invoiceableGoodsReceipts(int $organizationId, ?int $supplierId = null): array
    {
        $invoicedIds = SupplierInvoice::query()
            ->where('organization_id', $organizationId)
            ->pluck('goods_receipt_id');

        $query = GoodsReceipt::query()
            ->with(['items', 'purchaseOrder'])
            ->where('organization_id', $organizationId)
            ->whereNotIn('id', $invoicedIds)
            ->orderByDesc('id');

        if ($supplierId !== null) {
            $query->whereHas('purchaseOrder', fn ($q) => $q->where('supplier_id', $supplierId));
        }

        return $query->limit(100)->get()->map(function (GoodsReceipt $receipt) {
            return [
                'id' => $receipt->id,
                'purchaseOrderId' => $receipt->purchase_order_id,
                'purchaseOrderNumber' => $receipt->purchaseOrder?->number,
                'supplierId' => $receipt->purchaseOrder?->supplier_id,
                'suggestedAmount' => $this->suggestedAmountFromReceipt($receipt),
                'createdAt' => $receipt->created_at?->toIso8601String(),
            ];
        })->all();
    }

    public function assertPositiveMoneyDecimal(string $value, string $label = 'Amount'): void
    {
        if (! preg_match(self::MONEY_PATTERN, $value)) {
            throw new RuntimeException("{$label} must be a positive decimal with at most 11 digits before the decimal and 4 after.");
        }

        if (bccomp($value, '0', 4) <= 0) {
            throw new RuntimeException("{$label} must be greater than zero.");
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createWithUniqueNumber(array $attributes): SupplierInvoice
    {
        $attempts = 0;
        while (true) {
            try {
                return SupplierInvoice::query()->create([
                    ...$attributes,
                    'number' => $this->nextNumber((int) $attributes['organization_id']),
                ]);
            } catch (QueryException $e) {
                $attempts++;
                if ($attempts >= 5 || ! $this->isUniqueNumberViolation($e)) {
                    throw $e;
                }
            }
        }
    }

    private function nextNumber(int $organizationId): string
    {
        $prefix = 'SI-'.now()->format('Ymd').'-';

        $numbers = SupplierInvoice::query()
            ->where('organization_id', $organizationId)
            ->where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('number');

        $maxSeq = 0;
        foreach ($numbers as $number) {
            if (is_string($number) && preg_match('/-(\d+)$/', $number, $m)) {
                $maxSeq = max($maxSeq, (int) $m[1]);
            }
        }

        return $prefix.str_pad((string) ($maxSeq + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array{goodsReceiptId: int, totalAmount?: ?string}  $input
     */
    private function assertIdempotentPayloadMatches(SupplierInvoice $existing, array $input): void
    {
        if ((int) $existing->goods_receipt_id !== (int) $input['goodsReceiptId']) {
            throw new IdempotencyConflictException('Idempotency-Key already used with a different payload.');
        }

        if (array_key_exists('totalAmount', $input) && $input['totalAmount'] !== null && $input['totalAmount'] !== '') {
            if (bccomp((string) $existing->total_amount, (string) $input['totalAmount'], 4) !== 0) {
                throw new IdempotencyConflictException('Idempotency-Key already used with a different payload.');
            }
        }
    }

    private function isUniqueNumberViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'supplier_invoices_organization_id_number_unique')
            || (str_contains($message, 'UNIQUE constraint failed') && str_contains($message, 'number'));
    }

    private function isUniqueIdempotencyViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'supplier_invoices_organization_id_idempotency_key_unique')
            || (str_contains($message, 'UNIQUE constraint failed') && str_contains($message, 'idempotency_key'));
    }

    private function isUniqueGoodsReceiptViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'supplier_invoices_organization_id_goods_receipt_id_unique')
            || (str_contains($message, 'UNIQUE constraint failed') && str_contains($message, 'goods_receipt_id'));
    }
}
