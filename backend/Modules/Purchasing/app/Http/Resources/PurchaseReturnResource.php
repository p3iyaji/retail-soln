<?php

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Purchasing\Models\PurchaseReturn */
class PurchaseReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizationId' => $this->organization_id,
            'goodsReceiptId' => $this->goods_receipt_id,
            'purchaseOrderId' => $this->purchase_order_id,
            'supplierId' => $this->supplier_id,
            'storeId' => $this->store_id,
            'creditAmount' => $this->credit_amount,
            'notes' => $this->notes,
            'returnedBy' => $this->returned_by,
            'idempotencyKey' => $this->idempotency_key,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier === null ? null : [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'code' => $this->supplier->code,
            ]),
            'store' => $this->whenLoaded('store', fn () => $this->store === null ? null : [
                'id' => $this->store->id,
                'name' => $this->store->name,
            ]),
            'purchaseOrder' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder === null
                ? null
                : [
                    'id' => $this->purchaseOrder->id,
                    'number' => $this->purchaseOrder->number,
                    'status' => $this->purchaseOrder->status?->value ?? $this->purchaseOrder->status,
                ]),
            'goodsReceipt' => $this->whenLoaded('goodsReceipt', fn () => $this->goodsReceipt === null ? null : [
                'id' => $this->goodsReceipt->id,
                'purchaseOrderId' => $this->goodsReceipt->purchase_order_id,
            ]),
            'items' => PurchaseReturnItemResource::collection($this->whenLoaded('items')),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
