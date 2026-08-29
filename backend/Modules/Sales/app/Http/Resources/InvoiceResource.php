<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Sales\Models\Invoice */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'saleId' => $this->sale_id,
            'proformaInvoiceId' => $this->proforma_invoice_id,
            'invoiceNumber' => $this->invoice_number,
            'status' => $this->status,
            'subtotal' => $this->subtotal,
            'taxTotal' => $this->tax_total,
            'total' => $this->total,
            'issuedAt' => $this->issued_at?->toIso8601String(),
        ];
    }
}
