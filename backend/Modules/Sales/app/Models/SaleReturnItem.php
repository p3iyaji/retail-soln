<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Products\Models\ProductVariant;

class SaleReturnItem extends Model
{
    protected $fillable = [
        'organization_id',
        'sale_return_id',
        'sale_item_id',
        'product_variant_id',
        'qty',
        'unit_price',
        'discount',
        'tax_amount',
        'line_total',
        'inventory_movement_id',
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

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }
}
