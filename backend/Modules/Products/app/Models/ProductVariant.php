<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'organization_id',
        'product_id',
        'name',
        'sku',
        'price',
        'cost_price',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'cost_price' => 'decimal:4',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(Barcode::class)->orderByDesc('is_primary');
    }

    public function primaryBarcode(): ?Barcode
    {
        return $this->barcodes()->where('is_primary', true)->first()
            ?? $this->barcodes()->first();
    }
}
