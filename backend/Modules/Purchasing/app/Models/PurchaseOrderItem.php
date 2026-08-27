<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Products\Models\ProductVariant;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'organization_id',
        'purchase_order_id',
        'product_variant_id',
        'qty',
        'unit_cost',
        'line_total',
        'qty_received',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'line_total' => 'decimal:4',
            'qty_received' => 'decimal:4',
        ];
    }

    public function qtyRemaining(): string
    {
        $remaining = bcsub((string) $this->qty, (string) $this->qty_received, 4);

        return bccomp($remaining, '0', 4) < 0 ? '0.0000' : $remaining;
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
