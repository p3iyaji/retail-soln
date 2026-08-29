<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Customers\Models\Customer;
use Modules\Sales\Enums\InstallmentPlanStatus;
use Modules\Sales\Enums\InstallmentStatus;
use Modules\Sales\Models\Installment;
use Modules\Sales\Models\InstallmentPlan;
use RuntimeException;

class InstallmentPlanCancellationService
{
    public function __construct(private readonly InstallmentCollectionService $collections) {}

    public function cancel(InstallmentPlan $plan): InstallmentPlan
    {
        if ($plan->status !== InstallmentPlanStatus::Open) {
            throw new RuntimeException('Only open installment plans can be cancelled.');
        }

        return DB::transaction(function () use ($plan) {
            $locked = InstallmentPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InstallmentPlanStatus::Open) {
                throw new RuntimeException('Only open installment plans can be cancelled.');
            }

            $outstanding = $this->collections->outstandingForPlan($locked->id);

            Installment::query()
                ->where('installment_plan_id', $locked->id)
                ->whereIn('status', [InstallmentStatus::Pending, InstallmentStatus::Overdue])
                ->update(['status' => InstallmentStatus::Waived]);

            if (bccomp($outstanding, '0', 4) > 0) {
                $customer = Customer::query()
                    ->where('organization_id', $locked->organization_id)
                    ->whereKey($locked->customer_id)
                    ->lockForUpdate()
                    ->first();

                if ($customer === null) {
                    throw new RuntimeException('Customer not found.');
                }

                $customer->balance = bcsub((string) $customer->balance, $outstanding, 4);
                if (bccomp((string) $customer->balance, '0', 4) < 0) {
                    $customer->balance = '0.0000';
                }
                $customer->save();
            }

            $locked->update(['status' => InstallmentPlanStatus::Cancelled]);

            return $locked->fresh(['customer', 'installments', 'store']);
        });
    }
}
