<?php

namespace Modules\CustomerPortal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Products\Models\ProductVariant;

class PortalOrderItem extends Model
{
    protected $fillable = [
        'portal_order_id',
        'product_variant_id',
        'product_name',
        'variant_name',
        'sku',
        'quantity',
        'unit_list_price',
        'unit_net_price',
        'unit_gross_price',
        'line_tax',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_list_price' => 'decimal:4',
            'unit_net_price' => 'decimal:4',
            'unit_gross_price' => 'decimal:4',
            'line_tax' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PortalOrder::class, 'portal_order_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
