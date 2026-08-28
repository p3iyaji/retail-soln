<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Sales\Models\Sale */
class ReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'saleId' => $this->id,
            'saleUuid' => $this->uuid,
            'store' => $this->whenLoaded('store', fn () => $this->store === null ? null : [
                'id' => $this->store->id,
                'name' => $this->store->name,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'name' => $this->customer->name,
            ]),
            'items' => SaleItemResource::collection($this->whenLoaded('items')),
            'payments' => SalePaymentResource::collection($this->whenLoaded('payments')),
            'subtotal' => $this->subtotal,
            'taxTotal' => $this->tax_total,
            'total' => $this->total,
            'invoiceNumber' => $this->whenLoaded('invoice', fn () => $this->invoice?->invoice_number),
            'completedAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
