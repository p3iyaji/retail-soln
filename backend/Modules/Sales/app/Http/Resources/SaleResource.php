<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Sales\Models\Sale */
class SaleResource extends JsonResource
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
            'customerId' => $this->customer_id,
            'cashierId' => $this->cashier_id,
            'subtotal' => $this->subtotal,
            'discountTotal' => $this->discount_total,
            'taxTotal' => $this->tax_total,
            'total' => $this->total,
            'status' => $this->status?->value ?? $this->status,
            'notes' => $this->notes,
            'voidedAt' => $this->voided_at?->toIso8601String(),
            'voidedBy' => $this->voided_by,
            'idempotencyKey' => $this->idempotency_key,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'isWalkIn' => (bool) $this->customer->is_walk_in,
            ]),
            'store' => $this->whenLoaded('store', fn () => $this->store === null ? null : [
                'id' => $this->store->id,
                'name' => $this->store->name,
            ]),
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'payments' => SalePaymentResource::collection($this->whenLoaded('payments')),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice === null ? null : [
                'id' => $this->invoice->id,
                'invoiceNumber' => $this->invoice->invoice_number,
                'status' => $this->invoice->status,
                'total' => $this->invoice->total,
            ]),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
