<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Products\Models\Product;

class SupplierProduct extends Model
{
    protected $fillable = [
        'organization_id',
        'supplier_id',
        'product_id',
        'supplier_sku',
        'cost',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:4',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
