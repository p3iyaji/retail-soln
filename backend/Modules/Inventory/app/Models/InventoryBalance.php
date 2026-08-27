<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Products\Models\ProductVariant;

/**
 * Materialized stock balance. Quantity columns must only change via InventoryLedgerService (AD-2).
 */
class InventoryBalance extends Model
{
    public static bool $allowQuantityMutation = false;

    protected $fillable = [
        'organization_id',
        'location_type',
        'location_id',
        'product_variant_id',
        'available',
        'reserved',
        'damaged',
        'in_transit',
    ];

    protected function casts(): array
    {
        return [
            'available' => 'decimal:4',
            'reserved' => 'decimal:4',
            'damaged' => 'decimal:4',
            'in_transit' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $balance): void {
            if (static::$allowQuantityMutation) {
                return;
            }

            if ($balance->isDirty(['available', 'reserved', 'damaged', 'in_transit'])) {
                throw new LogicException(
                    'AD-2: Inventory balances may only be mutated via InventoryLedgerService.'
                );
            }
        });
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
