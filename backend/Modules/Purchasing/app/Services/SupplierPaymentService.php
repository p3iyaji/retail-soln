<?php

namespace Modules\Purchasing\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Purchasing\Models\SupplierPayment;
use RuntimeException;

class SupplierPaymentService
{
    public function __construct(
        private readonly SupplierInvoiceService $invoices,
    ) {}

    /**
     * @param  array{
     *   organizationId: int,
     *   supplierInvoiceId: int,
     *   userId: int,
     *   amount: string,
     *   method?: ?string,
     *   reference?: ?string,
     *   paidAt?: ?string,
     *   notes?: ?string,
     *   idempotencyKey?: ?string,
     * }  $input
     * @return array{payment: SupplierPayment, created: bool}
     */
    public function create(array $input): array
    {
        $idempotencyKey = $input['idempotencyKey'] ?? null;
        if ($idempotencyKey !== null && $idempotencyKey !== '' && strlen($idempotencyKey) > 64) {
            throw new RuntimeException('Idempotency-Key must be at most 64 characters.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = SupplierPayment::query()
                ->where('organization_id', $input['organizationId'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $this->assertIdempotentPayloadMatches($existing, $input);

                return [
                    'payment' => $existing->load(['invoice.payments', 'supplier']),
                    'created' => false,
                ];
            }
        }

        $this->invoices->assertPositiveMoneyDecimal($input['amount'], 'Payment amount');

        return DB::transaction(function () use ($input, $idempotencyKey) {
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = SupplierPayment::query()
                    ->where('organization_id', $input['organizationId'])
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $this->assertIdempotentPayloadMatches($existing, $input);

                    return [
                        'payment' => $existing->load(['invoice.payments', 'supplier']),
                        'created' => false,
                    ];
                }
            }

            $invoice = SupplierInvoice::query()
                ->with('payments')
                ->where('organization_id', $input['organizationId'])
                ->whereKey($input['supplierInvoiceId'])
                ->lockForUpdate()
                ->first();

            if ($invoice === null) {
                throw new RuntimeException('Supplier invoice not found.');
            }

            $remaining = $invoice->amountRemaining();
            if (bccomp($input['amount'], $remaining, 4) > 0) {
                throw new RuntimeException('Payment amount exceeds invoice remaining balance.');
            }

            try {
                $payment = SupplierPayment::query()->create([
                    'organization_id' => $input['organizationId'],
                    'supplier_id' => $invoice->supplier_id,
                    'supplier_invoice_id' => $invoice->id,
                    'amount' => $input['amount'],
                    'method' => $input['method'] ?? null,
                    'reference' => $input['reference'] ?? null,
                    'paid_at' => $input['paidAt'] ?? now(),
                    'notes' => $input['notes'] ?? null,
                    'created_by' => $input['userId'],
                    'idempotency_key' => ($idempotencyKey !== null && $idempotencyKey !== '') ? $idempotencyKey : null,
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueIdempotencyViolation($e) && $idempotencyKey !== null && $idempotencyKey !== '') {
                    $existing = SupplierPayment::query()
                        ->where('organization_id', $input['organizationId'])
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();

                    if ($existing !== null) {
                        $this->assertIdempotentPayloadMatches($existing, $input);

                        return [
                            'payment' => $existing->load(['invoice.payments', 'supplier']),
                            'created' => false,
                        ];
                    }
                }

                throw $e;
            }

            $invoice->unsetRelation('payments');
            $invoice->load('payments');
            $this->invoices->refreshStatus($invoice);

            // Optional AD-12 hook: Accounting journals deferred — no journal posting in 3.4.

            return [
                'payment' => $payment->load(['invoice.payments', 'supplier']),
                'created' => true,
            ];
        });
    }

    /**
     * @param  array{supplierInvoiceId: int, amount: string}  $input
     */
    private function assertIdempotentPayloadMatches(SupplierPayment $existing, array $input): void
    {
        if ((int) $existing->supplier_invoice_id !== (int) $input['supplierInvoiceId']) {
            throw new IdempotencyConflictException('Idempotency-Key already used with a different payload.');
        }

        if (bccomp((string) $existing->amount, (string) $input['amount'], 4) !== 0) {
            throw new IdempotencyConflictException('Idempotency-Key already used with a different payload.');
        }
    }

    private function isUniqueIdempotencyViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'supplier_payments_organization_id_idempotency_key_unique')
            || (str_contains($message, 'UNIQUE constraint failed') && str_contains($message, 'idempotency_key'));
    }
}
