<?php

namespace Modules\Sales\Services;

use App\Support\Money;
use Carbon\Carbon;
use Modules\Customers\Models\Customer;
use Modules\Sales\Enums\InstallmentPlanMode;
use RuntimeException;

class InstallmentPlanService
{
    /**
     * @param  array{
     *   mode: string,
     *   installments: list<array{sequence: int, amount: string, dueAt: string}>,
     * }  $input
     * @return array{
     *   mode: InstallmentPlanMode,
     *   principal: string,
     *   fee_total: string,
     *   installments: list<array{sequence: int, amount: string, due_at: string}>,
     * }
     */
    public function validateForSale(Customer $customer, string $saleTotal, string $depositTotal, array $input): array
    {
        if ($customer->is_walk_in) {
            throw new RuntimeException('Installment plans require a registered customer.');
        }

        if (! $customer->is_active) {
            throw new RuntimeException('Customer is inactive.');
        }

        $mode = InstallmentPlanMode::tryFrom($input['mode'] ?? '');
        if ($mode === null) {
            throw new RuntimeException('Invalid installment plan mode.');
        }

        $rows = $input['installments'] ?? [];
        if ($rows === []) {
            throw new RuntimeException('Installment plan requires at least one installment.');
        }

        if (bccomp($depositTotal, $saleTotal, 4) >= 0) {
            throw new RuntimeException('Deposit must be less than sale total when using an installment plan.');
        }

        $financed = '0.0000';
        $lines = [];
        $seenSequences = [];

        foreach ($rows as $row) {
            $sequence = (int) ($row['sequence'] ?? 0);
            if ($sequence < 1 || isset($seenSequences[$sequence])) {
                throw new RuntimeException('Installment sequences must be unique positive integers.');
            }
            $seenSequences[$sequence] = true;

            $amount = Money::toStorage((string) $row['amount']);
            if (bccomp($amount, '0', 4) <= 0) {
                throw new RuntimeException('Installment amount must be greater than zero.');
            }

            $dueAt = $this->parseDueDate((string) ($row['dueAt'] ?? ''));

            $lines[] = [
                'sequence' => $sequence,
                'amount' => $amount,
                'due_at' => $dueAt,
            ];
            $financed = bcadd($financed, $amount, 4);
        }

        usort($lines, fn (array $a, array $b) => $a['sequence'] <=> $b['sequence']);

        $balanceDue = Money::toStorage(bcsub($saleTotal, $depositTotal, 4));
        if (! Money::equalsAtCurrency($financed, $balanceDue)) {
            throw new RuntimeException('Installment amounts must equal the sale balance after deposit.');
        }

        $availableCredit = bcsub((string) $customer->credit_limit, (string) $customer->balance, 4);
        if (bccomp($availableCredit, $financed, 4) < 0) {
            throw new RuntimeException('Customer credit limit exceeded.');
        }

        return [
            'mode' => $mode,
            'principal' => $financed,
            'fee_total' => '0.0000',
            'installments' => $lines,
        ];
    }

    private function parseDueDate(string $value): string
    {
        if ($value === '') {
            throw new RuntimeException('Each installment requires a due date.');
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            throw new RuntimeException('Invalid installment due date.');
        }
    }
}
