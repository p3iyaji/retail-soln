<?php

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Purchasing\Models\SupplierPayment */
class SupplierPaymentResource extends JsonResource
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
            'supplierInvoiceId' => $this->supplier_invoice_id,
            'amount' => $this->amount,
            'method' => $this->method,
            'reference' => $this->reference,
            'paidAt' => $this->paid_at?->toIso8601String(),
            'notes' => $this->notes,
            'createdBy' => $this->created_by,
            'idempotencyKey' => $this->idempotency_key,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier === null ? null : [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'code' => $this->supplier->code,
            ]),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice === null ? null : [
                'id' => $this->invoice->id,
                'number' => $this->invoice->number,
                'status' => $this->invoice->status?->value ?? $this->invoice->status,
                'totalAmount' => $this->invoice->total_amount,
                'amountPaid' => $this->invoice->amountPaid(),
                'amountRemaining' => $this->invoice->amountRemaining(),
                'supplierId' => $this->invoice->supplier_id,
            ]),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
