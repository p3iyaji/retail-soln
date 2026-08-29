<?php

namespace Modules\Sales\Services;

use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Modules\Customers\Models\Customer;
use Modules\Sales\Enums\CashMovementType;
use Modules\Sales\Enums\InstallmentPlanStatus;
use Modules\Sales\Enums\InstallmentStatus;
use Modules\Sales\Enums\PaymentMethod;
use Modules\Sales\Models\CashMovement;
use Modules\Sales\Models\Installment;
use Modules\Sales\Models\InstallmentPayment;
use Modules\Sales\Models\InstallmentPlan;
use RuntimeException;

class InstallmentCollectionService
{
    public function __construct(private readonly CashierSessionService $sessions) {}

    /**
     * @param  array{
     *   organizationId: int,
     *   storeId: int,
     *   userId: int,
     *   planId: int,
     *   installmentId: int,
     *   sessionId: int,
     *   idempotencyKey: string,
     *   allowPartial: bool,
     *   payment: array{method: string, amount: string, provider: string, reference?: ?string},
     * }  $input
     * @return array{payment: InstallmentPayment, created: bool}
     */
    public function collect(array $input): array
    {
        $idempotencyKey = $input['idempotencyKey'];
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw new RuntimeException('Idempotency-Key must be between 1 and 64 characters.');
        }

        $existing = InstallmentPayment::query()
            ->where('organization_id', $input['organizationId'])
            ->where('idempotency_key', $idempotencyKey)
            ->with(['installment', 'plan.customer', 'plan.store', 'plan.installments', 'user'])
            ->first();

        if ($existing !== null) {
            $this->assertIdempotentPayloadMatches($existing, $input);

            return ['payment' => $existing, 'created' => false];
        }

        $session = $this->sessions->assertOpenSessionForSale(
            $input['organizationId'],
            $input['storeId'],
            $input['userId'],
            $input['sessionId'],
        );

        $amount = Money::toStorage((string) $input['payment']['amount']);
        if (bccomp($amount, '0', 4) <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($input, $session, $amount) {
            $replay = InstallmentPayment::query()
                ->where('organization_id', $input['organizationId'])
                ->where('idempotency_key', $input['idempotencyKey'])
                ->lockForUpdate()
                ->first();

            if ($replay !== null) {
                $this->assertIdempotentPayloadMatches($replay, $input);

                return ['payment' => $replay->load(['installment', 'plan.customer', 'plan.store', 'plan.installments', 'user']), 'created' => false];
            }

            $plan = InstallmentPlan::query()
                ->where('organization_id', $input['organizationId'])
                ->whereKey($input['planId'])
                ->lockForUpdate()
                ->first();

            if ($plan === null || $plan->store_id !== $input['storeId']) {
                throw new RuntimeException('Installment plan not found.');
            }

            if ($plan->status !== InstallmentPlanStatus::Open) {
                throw new RuntimeException('Installment plan is not open.');
            }

            $installment = Installment::query()
                ->where('organization_id', $input['organizationId'])
                ->where('installment_plan_id', $plan->id)
                ->whereKey($input['installmentId'])
                ->lockForUpdate()
                ->first();

            if ($installment === null) {
                throw new RuntimeException('Installment not found.');
            }

            if ($installment->installment_plan_id !== $plan->id) {
                throw new RuntimeException('Installment does not belong to this plan.');
            }

            if ($installment->status === InstallmentStatus::Paid || $installment->status === InstallmentStatus::Waived) {
                throw new RuntimeException('Installment is already settled.');
            }

            $planOutstanding = $this->outstandingForPlan($plan->id);
            if (bccomp($amount, $planOutstanding, 4) > 0) {
                throw new RuntimeException('Payment exceeds remaining plan balance.');
            }

            $installmentDue = bcsub((string) $installment->amount, (string) $installment->paid_amount, 4);
            if (bccomp($installmentDue, '0', 4) <= 0) {
                throw new RuntimeException('Installment is already settled.');
            }

            if (! $input['allowPartial']) {
                if (! Money::equalsAtCurrency($amount, $installmentDue)) {
                    throw new RuntimeException('Full installment amount is required.');
                }
            } elseif (bccomp($amount, $installmentDue, 4) > 0) {
                throw new RuntimeException('Payment exceeds installment balance.');
            }

            $customer = Customer::query()
                ->where('organization_id', $input['organizationId'])
                ->whereKey($plan->customer_id)
                ->lockForUpdate()
                ->first();

            if ($customer === null) {
                throw new RuntimeException('Customer not found.');
            }

            $payment = InstallmentPayment::query()->create([
                'organization_id' => $input['organizationId'],
                'installment_plan_id' => $plan->id,
                'installment_id' => $installment->id,
                'session_id' => $session->id,
                'user_id' => $input['userId'],
                'method' => $input['payment']['method'],
                'provider' => $input['payment']['provider'],
                'amount' => $amount,
                'reference' => $input['payment']['reference'] ?? null,
                'idempotency_key' => $input['idempotencyKey'],
            ]);

            $newPaid = bcadd((string) $installment->paid_amount, $amount, 4);
            $installment->paid_amount = $newPaid;

            if (bccomp($newPaid, (string) $installment->amount, 4) >= 0) {
                $installment->status = InstallmentStatus::Paid;
                $installment->paid_at = now();
                $installment->payment_id = $payment->id;
            } else {
                $installment->status = InstallmentStatus::Pending;
            }

            $installment->save();

            $customer->balance = bcsub((string) $customer->balance, $amount, 4);
            if (bccomp((string) $customer->balance, '0', 4) < 0) {
                $customer->balance = '0.0000';
            }
            $customer->save();

            if ($this->outstandingForPlan($plan->id) === '0.0000') {
                $plan->status = InstallmentPlanStatus::Closed;
                $plan->save();
            }

            if ($input['payment']['method'] === PaymentMethod::Cash->value) {
                CashMovement::query()->create([
                    'organization_id' => $session->organization_id,
                    'session_id' => $session->id,
                    'type' => CashMovementType::InstallmentCash,
                    'amount' => $amount,
                    'reference_type' => 'installment_payment',
                    'reference_id' => $payment->id,
                    'user_id' => $input['userId'],
                    'notes' => 'Installment #'.$installment->sequence.' plan #'.$plan->id,
                ]);
            }

            return [
                'payment' => $payment->load(['installment', 'plan.customer', 'plan.store', 'plan.installments', 'user']),
                'created' => true,
            ];
        });
    }

    public function outstandingForPlan(int $planId): string
    {
        $rows = Installment::query()
            ->where('installment_plan_id', $planId)
            ->whereIn('status', [InstallmentStatus::Pending, InstallmentStatus::Overdue])
            ->get(['amount', 'paid_amount']);

        $outstanding = '0.0000';
        foreach ($rows as $row) {
            $due = bcsub((string) $row->amount, (string) $row->paid_amount, 4);
            if (bccomp($due, '0', 4) > 0) {
                $outstanding = bcadd($outstanding, $due, 4);
            }
        }

        return Money::toStorage($outstanding);
    }

    /**
     * @param  array{
     *   planId: int,
     *   installmentId: int,
     *   sessionId: int,
     *   payment: array{method: string, amount: string, provider: string},
     * }  $input
     */
    private function assertIdempotentPayloadMatches(InstallmentPayment $existing, array $input): void
    {
        $normalized = [
            'planId' => (int) $input['planId'],
            'installmentId' => (int) $input['installmentId'],
            'sessionId' => (int) $input['sessionId'],
            'method' => $input['payment']['method'],
            'amount' => Money::toStorage((string) $input['payment']['amount']),
            'provider' => $input['payment']['provider'],
        ];

        $existingPayload = [
            'planId' => $existing->installment_plan_id,
            'installmentId' => $existing->installment_id,
            'sessionId' => (int) $existing->session_id,
            'method' => $existing->method,
            'amount' => Money::toStorage((string) $existing->amount),
            'provider' => $existing->provider,
        ];

        if ($normalized !== $existingPayload) {
            throw new IdempotencyConflictException(
                'Idempotency-Key was already used with a different request body.'
            );
        }
    }
}
