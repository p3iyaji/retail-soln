<?php

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Purchasing\Models\SupplierInvoice */
class SupplierInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizationId' => $this->organization_id,
            'supplierId' => $this->supplier_id,
            'purchaseOrderId' => $this->purchase_order_id,
            'goodsReceiptId' => $this->goods_receipt_id,
            'number' => $this->number,
            'status' => $this->status?->value ?? $this->status,
            'totalAmount' => $this->total_amount,
            'amountPaid' => $this->amountPaid(),
            'amountRemaining' => $this->amountRemaining(),
            'notes' => $this->notes,
            'createdBy' => $this->created_by,
            'idempotencyKey' => $this->idempotency_key,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier === null ? null : [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'code' => $this->supplier->code,
            ]),
            'purchaseOrder' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder === null ? null : [
                'id' => $this->purchaseOrder->id,
                'number' => $this->purchaseOrder->number,
                'status' => $this->purchaseOrder->status?->value ?? $this->purchaseOrder->status,
            ]),
            'goodsReceipt' => $this->whenLoaded('goodsReceipt', fn () => $this->goodsReceipt === null ? null : [
                'id' => $this->goodsReceipt->id,
                'purchaseOrderId' => $this->goodsReceipt->purchase_order_id,
                'createdAt' => $this->goodsReceipt->created_at?->toIso8601String(),
            ]),
            'payments' => SupplierPaymentResource::collection($this->whenLoaded('payments')),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
