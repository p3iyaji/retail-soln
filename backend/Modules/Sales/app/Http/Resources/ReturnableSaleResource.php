<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Services\SaleReturnService;

/** @mixin \Modules\Sales\Models\Sale */
class ReturnableSaleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $returnable = app(SaleReturnService::class)->returnableQuantities($this->resource);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'storeId' => $this->store_id,
            'total' => $this->total,
            'taxTotal' => $this->tax_total,
            'subtotal' => $this->subtotal,
            'status' => $this->status?->value ?? $this->status,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ]),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice === null ? null : [
                'invoiceNumber' => $this->invoice->invoice_number,
            ]),
            'items' => $this->whenLoaded('items', function () use ($returnable) {
                return $this->items->map(fn ($item) => [
                    'id' => $item->id,
                    'variantId' => $item->product_variant_id,
                    'qty' => $item->qty,
                    'returnedQty' => bcsub(
                        bcadd((string) $item->qty, '0', 4),
                        bcadd($returnable[(string) $item->id] ?? '0', '0', 4),
                        4,
                    ),
                    'returnableQty' => $returnable[(string) $item->id] ?? '0.0000',
                    'unitPrice' => $item->unit_price,
                    'discount' => $item->discount,
                    'taxAmount' => $item->tax_amount,
                    'lineTotal' => $item->line_total,
                    'variant' => $item->relationLoaded('productVariant') && $item->productVariant !== null ? [
                        'name' => $item->productVariant->name,
                        'sku' => $item->productVariant->sku,
                    ] : null,
                ]);
            }),
            'payments' => SalePaymentResource::collection($this->whenLoaded('payments')),
            'completedAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
