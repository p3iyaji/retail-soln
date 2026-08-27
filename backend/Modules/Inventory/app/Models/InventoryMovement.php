<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Enums\BalanceBucket;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Products\Models\ProductVariant;

class InventoryMovement extends Model
{
    protected $fillable = [
        'uuid',
        'organization_id',
        'location_type',
        'location_id',
        'product_variant_id',
        'type',
        'quantity',
        'bucket',
        'unit_cost',
        'reference_type',
        'reference_id',
        'user_id',
        'notes',
        'balance_after',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'location_type' => LocationType::class,
            'bucket' => BalanceBucket::class,
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'balance_after' => 'decimal:4',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
