<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sales\Enums\CashierSessionStatus;
use Modules\Sales\Enums\CashMovementType;
use Modules\Sales\Enums\PaymentMethod;
use Modules\Sales\Models\CashierSession;
use Modules\Sales\Models\CashMovement;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleReturn;
use Modules\Sales\Models\Terminal;
use RuntimeException;

class CashierSessionService
{
    public function __construct(private readonly TerminalService $terminals) {}

    public function open(
        int $organizationId,
        int $storeId,
        int $userId,
        string $terminalName,
        string $openingFloat,
    ): CashierSession {
        $openingFloat = bcadd($openingFloat, '0', 4);

        if (bccomp($openingFloat, '0', 4) < 0) {
            throw new RuntimeException('Opening float cannot be negative.');
        }

        return DB::transaction(function () use ($organizationId, $storeId, $userId, $terminalName, $openingFloat) {
            $terminal = $this->terminals->resolveOrCreate($organizationId, $storeId, $terminalName);

            $existingOnTerminal = CashierSession::query()
                ->where('organization_id', $organizationId)
                ->where('terminal_id', $terminal->id)
                ->where('status', CashierSessionStatus::Open)
                ->lockForUpdate()
                ->first();

            if ($existingOnTerminal !== null) {
                throw new RuntimeException('This terminal already has an open session.');
            }

            return CashierSession::query()->create([
                'organization_id' => $organizationId,
                'store_id' => $storeId,
                'terminal_id' => $terminal->id,
                'user_id' => $userId,
                'status' => CashierSessionStatus::Open,
                'opening_float' => $openingFloat,
                'opened_at' => now(),
            ])->load(['terminal', 'cashier', 'store']);
        });
    }

    public function findOpenForTerminal(
        int $organizationId,
        int $storeId,
        string $terminalName,
    ): ?CashierSession {
        $terminal = Terminal::query()
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
            ->where('name', trim($terminalName))
            ->first();

        if ($terminal === null) {
            return null;
        }

        return CashierSession::query()
            ->where('organization_id', $organizationId)
            ->where('terminal_id', $terminal->id)
            ->where('status', CashierSessionStatus::Open)
            ->with(['terminal', 'cashier', 'store'])
            ->first();
    }

    public function recordCashMovement(
        CashierSession $session,
        int $userId,
        CashMovementType $type,
        string $amount,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): CashMovement {
        if ($session->status !== CashierSessionStatus::Open) {
            throw new RuntimeException('Session is not open.');
        }

        $amount = bcadd($amount, '0', 4);

        if (bccomp($amount, '0', 4) <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }

        if (! in_array($type, [CashMovementType::CashIn, CashMovementType::CashOut], true)) {
            throw new RuntimeException('Manual cash movements must be cash in or cash out.');
        }

        return CashMovement::query()->create([
            'organization_id' => $session->organization_id,
            'session_id' => $session->id,
            'type' => $type,
            'amount' => $amount,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'user_id' => $userId,
            'notes' => $notes,
        ]);
    }

    public function recordSaleCashPayments(Sale $sale, int $userId): void
    {
        if ($sale->session_id === null) {
            return;
        }

        $session = CashierSession::query()->whereKey($sale->session_id)->first();
        if ($session === null || $session->status !== CashierSessionStatus::Open) {
            return;
        }

        $sale->loadMissing('payments');

        foreach ($sale->payments as $payment) {
            if ($payment->method !== PaymentMethod::Cash->value) {
                continue;
            }

            $exists = CashMovement::query()
                ->where('session_id', $session->id)
                ->where('type', CashMovementType::SaleCash)
                ->where('reference_type', 'sale')
                ->where('reference_id', $sale->id)
                ->exists();

            if ($exists) {
                continue;
            }

            CashMovement::query()->create([
                'organization_id' => $session->organization_id,
                'session_id' => $session->id,
                'type' => CashMovementType::SaleCash,
                'amount' => bcadd((string) $payment->amount, '0', 4),
                'reference_type' => 'sale',
                'reference_id' => $sale->id,
                'user_id' => $userId,
                'notes' => 'Sale #'.$sale->id,
            ]);
        }
    }

    public function recordVoidCashRefunds(Sale $sale, int $userId): void
    {
        if ($sale->session_id === null) {
            return;
        }

        $session = CashierSession::query()->whereKey($sale->session_id)->first();
        if ($session === null) {
            return;
        }

        $sale->loadMissing('payments');

        foreach ($sale->payments as $payment) {
            if ($payment->method !== PaymentMethod::Cash->value) {
                continue;
            }

            $exists = CashMovement::query()
                ->where('session_id', $session->id)
                ->where('type', CashMovementType::RefundCash)
                ->where('reference_type', 'sale')
                ->where('reference_id', $sale->id)
                ->exists();

            if ($exists) {
                continue;
            }

            CashMovement::query()->create([
                'organization_id' => $session->organization_id,
                'session_id' => $session->id,
                'type' => CashMovementType::RefundCash,
                'amount' => bcadd((string) $payment->amount, '0', 4),
                'reference_type' => 'sale',
                'reference_id' => $sale->id,
                'user_id' => $userId,
                'notes' => 'Void sale #'.$sale->id,
            ]);
        }
    }

    public function recordReturnCashRefunds(SaleReturn $saleReturn, int $userId): void
    {
        if ($saleReturn->session_id === null) {
            return;
        }

        $session = CashierSession::query()->whereKey($saleReturn->session_id)->first();
        if ($session === null) {
            return;
        }

        $saleReturn->loadMissing('refunds');

        foreach ($saleReturn->refunds as $refund) {
            if ($refund->method !== PaymentMethod::Cash->value) {
                continue;
            }

            $exists = CashMovement::query()
                ->where('session_id', $session->id)
                ->where('type', CashMovementType::RefundCash)
                ->where('reference_type', 'sale_return')
                ->where('reference_id', $saleReturn->id)
                ->exists();

            if ($exists) {
                continue;
            }

            CashMovement::query()->create([
                'organization_id' => $session->organization_id,
                'session_id' => $session->id,
                'type' => CashMovementType::RefundCash,
                'amount' => bcadd((string) $refund->amount, '0', 4),
                'reference_type' => 'sale_return',
                'reference_id' => $saleReturn->id,
                'user_id' => $userId,
                'notes' => 'Return for sale #'.$saleReturn->sale_id,
            ]);
        }
    }

    public function close(CashierSession $session, int $userId, string $closingCash, ?string $notes = null): CashierSession
    {
        if ($session->status !== CashierSessionStatus::Open) {
            throw new RuntimeException('Session is not open.');
        }

        if ($session->user_id !== $userId) {
            throw new RuntimeException('Only the cashier who opened this session may close it.');
        }

        $closingCash = bcadd($closingCash, '0', 4);

        if (bccomp($closingCash, '0', 4) < 0) {
            throw new RuntimeException('Closing cash cannot be negative.');
        }

        return DB::transaction(function () use ($session, $closingCash, $notes) {
            $locked = CashierSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CashierSessionStatus::Open) {
                throw new RuntimeException('Session is not open.');
            }

            $expected = $this->computeExpectedCash($locked);
            $variance = bcsub($closingCash, $expected, 4);

            $locked->update([
                'status' => CashierSessionStatus::Closed,
                'expected_cash' => $expected,
                'closing_cash' => $closingCash,
                'variance' => $variance,
                'closed_at' => now(),
                'notes' => $notes,
            ]);

            return $locked->fresh(['terminal', 'cashier', 'store', 'cashMovements']);
        });
    }

    public function assertOpenSessionForSale(int $organizationId, int $storeId, int $userId, int $sessionId): CashierSession
    {
        $session = CashierSession::query()
            ->where('organization_id', $organizationId)
            ->whereKey($sessionId)
            ->first();

        if ($session === null) {
            throw new RuntimeException('Cashier session not found.');
        }

        if ($session->status !== CashierSessionStatus::Open) {
            throw new RuntimeException('Cashier session is not open.');
        }

        if ($session->store_id !== $storeId) {
            throw new RuntimeException('Cashier session does not belong to this store.');
        }

        if ($session->user_id !== $userId) {
            throw new RuntimeException('Cashier session does not belong to this user.');
        }

        return $session;
    }

    public function computeExpectedCash(CashierSession $session): string
    {
        $total = bcadd((string) $session->opening_float, '0', 4);

        $movements = CashMovement::query()
            ->where('session_id', $session->id)
            ->get(['type', 'amount']);

        foreach ($movements as $movement) {
            $amount = bcadd((string) $movement->amount, '0', 4);

            $total = match ($movement->type) {
                CashMovementType::CashIn, CashMovementType::SaleCash, CashMovementType::InstallmentCash => bcadd($total, $amount, 4),
                CashMovementType::CashOut, CashMovementType::RefundCash => bcsub($total, $amount, 4),
            };
        }

        return $total;
    }

    /**
     * @return array{cashIn: string, cashOut: string, saleCash: string, refundCash: string, installmentCash: string}
     */
    public function summarizeMovements(CashierSession $session): array
    {
        $cashIn = '0.0000';
        $cashOut = '0.0000';
        $saleCash = '0.0000';
        $refundCash = '0.0000';
        $installmentCash = '0.0000';

        foreach ($session->cashMovements as $movement) {
            $amount = bcadd((string) $movement->amount, '0', 4);

            match ($movement->type) {
                CashMovementType::CashIn => $cashIn = bcadd($cashIn, $amount, 4),
                CashMovementType::CashOut => $cashOut = bcadd($cashOut, $amount, 4),
                CashMovementType::SaleCash => $saleCash = bcadd($saleCash, $amount, 4),
                CashMovementType::RefundCash => $refundCash = bcadd($refundCash, $amount, 4),
                CashMovementType::InstallmentCash => $installmentCash = bcadd($installmentCash, $amount, 4),
            };
        }

        return [
            'cashIn' => $cashIn,
            'cashOut' => $cashOut,
            'saleCash' => $saleCash,
            'refundCash' => $refundCash,
            'installmentCash' => $installmentCash,
        ];
    }
}
