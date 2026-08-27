<?php

namespace Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Pricing\Enums\PromotionRuleType;
use Modules\Products\Models\ProductVariant;

class PromotionRule extends Model
{
    protected $fillable = [
        'promotion_id',
        'type',
        'value',
        'product_variant_id',
        'buy_qty',
        'get_qty',
        'get_discount_percent',
    ];

    protected function casts(): array
    {
        return [
            'type' => PromotionRuleType::class,
            'value' => 'decimal:4',
            'buy_qty' => 'decimal:4',
            'get_qty' => 'decimal:4',
            'get_discount_percent' => 'decimal:4',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
