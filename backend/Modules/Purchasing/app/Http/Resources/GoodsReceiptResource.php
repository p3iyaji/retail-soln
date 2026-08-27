<?php

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Purchasing\Models\GoodsReceipt */
class GoodsReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizationId' => $this->organization_id,
            'purchaseOrderId' => $this->purchase_order_id,
            'storeId' => $this->store_id,
            'receivedBy' => $this->received_by,
            'notes' => $this->notes,
            'idempotencyKey' => $this->idempotency_key,
            'store' => $this->whenLoaded('store', fn () => $this->store === null ? null : [
                'id' => $this->store->id,
                'name' => $this->store->name,
            ]),
            'purchaseOrder' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder === null
                ? null
                : new PurchaseOrderResource($this->purchaseOrder)),
            'items' => GoodsReceiptItemResource::collection($this->whenLoaded('items')),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
