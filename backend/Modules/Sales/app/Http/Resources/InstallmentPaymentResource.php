<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Services\InstallmentCollectionService;

/** @mixin \Modules\Sales\Models\InstallmentPayment */
class InstallmentPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $plan = $this->whenLoaded('plan', fn () => $this->plan);
        $installment = $this->whenLoaded('installment', fn () => $this->installment);

        return [
            'id' => $this->id,
            'planId' => $this->installment_plan_id,
            'installmentId' => $this->installment_id,
            'method' => $this->method,
            'provider' => $this->provider,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'collectedAt' => $this->created_at?->toIso8601String(),
            'plan' => $plan ? new InstallmentPlanResource($this->plan) : null,
            'installment' => $installment ? new InstallmentResource($this->installment) : null,
            'statement' => $this->when(
                $this->relationLoaded('plan') && $this->plan !== null,
                function () {
                    $outstanding = app(InstallmentCollectionService::class)->outstandingForPlan($this->plan->id);

                    return [
                        'planId' => $this->plan->id,
                        'saleId' => $this->plan->sale_id,
                        'customerName' => $this->plan->customer?->name,
                        'paymentAmount' => $this->amount,
                        'installmentSequence' => $this->installment?->sequence,
                        'planOutstanding' => $outstanding,
                        'planStatus' => $this->plan->status?->value ?? $this->plan->status,
                        'collectedAt' => $this->created_at?->toIso8601String(),
                    ];
                }
            ),
        ];
    }
}
