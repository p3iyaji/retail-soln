<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Products\Models\ProductVariant;

class InvoiceItem extends Model
{
    protected $fillable = [
        'organization_id',
        'invoice_id',
        'product_variant_id',
        'qty',
        'unit_price',
        'discount',
        'tax_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
