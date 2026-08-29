<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Enums\ProformaInvoiceStatus;

/** @mixin \Modules\Sales\Models\ProformaInvoice */
class ProformaInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'storeId' => $this->store_id,
            'customerId' => $this->customer_id,
            'number' => $this->number,
            'status' => $this->status instanceof ProformaInvoiceStatus ? $this->status->value : $this->status,
            'subtotal' => $this->subtotal,
            'discountTotal' => $this->discount_total,
            'taxTotal' => $this->tax_total,
            'total' => $this->total,
            'validUntil' => $this->valid_until?->toDateString(),
            'notes' => $this->notes,
            'issuedAt' => $this->issued_at?->toIso8601String(),
            'convertedSaleId' => $this->converted_sale_id,
            'convertedInvoiceId' => $this->converted_invoice_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'accountNumber' => $this->customer->account_number,
                'email' => $this->customer->email,
            ]),
            'store' => $this->whenLoaded('store', fn () => $this->store === null ? null : [
                'id' => $this->store->id,
                'name' => $this->store->name,
            ]),
            'items' => ProformaInvoiceItemResource::collection($this->whenLoaded('items')),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
