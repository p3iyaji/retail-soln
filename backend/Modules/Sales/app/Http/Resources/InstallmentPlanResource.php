<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Enums\InstallmentPlanMode;
use Modules\Sales\Enums\InstallmentPlanStatus;
use Modules\Sales\Services\InstallmentCollectionService;

/** @mixin \Modules\Sales\Models\InstallmentPlan */
class InstallmentPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $outstanding = app(InstallmentCollectionService::class)->outstandingForPlan($this->id);

        return [
            'id' => $this->id,
            'saleId' => $this->sale_id,
            'customerId' => $this->customer_id,
            'storeId' => $this->store_id,
            'principal' => $this->principal,
            'feeTotal' => $this->fee_total,
            'outstandingBalance' => $outstanding,
            'mode' => $this->mode instanceof InstallmentPlanMode ? $this->mode->value : $this->mode,
            'status' => $this->status instanceof InstallmentPlanStatus ? $this->status->value : $this->status,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'accountNumber' => $this->customer->account_number,
                'phone' => $this->customer->phone,
            ]),
            'installments' => InstallmentResource::collection($this->whenLoaded('installments')),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
