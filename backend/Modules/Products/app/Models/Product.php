<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Products\Database\Factories\ProductFactory;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'sku',
        'barcode',
        'description',
        'category_id',
        'brand_id',
        'unit_id',
        'supplier_id',
        'base_price',
        'cost_price',
        'reorder_level',
        'min_stock',
        'max_stock',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:4',
            'cost_price' => 'decimal:4',
            'reorder_level' => 'decimal:4',
            'min_stock' => 'decimal:4',
            'max_stock' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }
}
