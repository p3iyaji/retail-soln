<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Products\Models\ProductVariant;

class GoodsReceiptItem extends Model
{
    protected $fillable = [
        'organization_id',
        'goods_receipt_id',
        'purchase_order_item_id',
        'product_variant_id',
        'qty',
        'unit_cost',
        'inventory_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }

    public function purchaseReturnItems(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function qtyReturned(): string
    {
        if ($this->relationLoaded('purchaseReturnItems')) {
            $total = '0.0000';
            foreach ($this->purchaseReturnItems as $item) {
                $total = bcadd($total, bcadd((string) $item->qty, '0', 4), 4);
            }

            return $total;
        }

        $total = '0.0000';
        foreach ($this->purchaseReturnItems()->pluck('qty') as $qty) {
            $total = bcadd($total, bcadd((string) $qty, '0', 4), 4);
        }

        return $total;
    }

    public function qtyReturnable(): string
    {
        return bcsub(bcadd((string) $this->qty, '0', 4), $this->qtyReturned(), 4);
    }
}
