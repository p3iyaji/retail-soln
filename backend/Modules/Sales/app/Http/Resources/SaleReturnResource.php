<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Sales\Models\SaleReturn */
class SaleReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'organizationId' => $this->organization_id,
            'storeId' => $this->store_id,
            'saleId' => $this->sale_id,
            'processedBy' => $this->processed_by,
            'sessionId' => $this->session_id,
            'reason' => $this->reason?->value ?? $this->reason,
            'total' => $this->total,
            'items' => SaleReturnItemResource::collection($this->whenLoaded('items')),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
            'sale' => $this->whenLoaded('sale', fn () => $this->sale === null ? null : [
                'id' => $this->sale->id,
                'uuid' => $this->sale->uuid,
                'total' => $this->sale->total,
            ]),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
