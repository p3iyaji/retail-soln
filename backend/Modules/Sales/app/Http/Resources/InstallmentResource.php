<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Enums\InstallmentStatus;

/** @mixin \Modules\Sales\Models\Installment */
class InstallmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof InstallmentStatus ? $this->status->value : $this->status;
        $balanceDue = bcsub((string) $this->amount, (string) $this->paid_amount, 4);
        $isOverdue = $status === InstallmentStatus::Pending->value
            && $this->due_at !== null
            && $this->due_at->isPast()
            && bccomp($balanceDue, '0', 4) > 0;

        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'amount' => $this->amount,
            'paidAmount' => $this->paid_amount,
            'balanceDue' => $balanceDue,
            'dueAt' => $this->due_at?->toDateString(),
            'status' => $isOverdue ? InstallmentStatus::Overdue->value : $status,
            'isOverdue' => $isOverdue,
            'paidAt' => $this->paid_at?->toIso8601String(),
        ];
    }
}
