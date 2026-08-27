<?php

namespace Modules\Pricing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Pricing\Models\PromotionRule */
class PromotionRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'promotionId' => $this->promotion_id,
            'type' => $this->type->value,
            'value' => $this->value,
            'productVariantId' => $this->product_variant_id,
            'buyQty' => $this->buy_qty,
            'getQty' => $this->get_qty,
            'getDiscountPercent' => $this->get_discount_percent,
        ];
    }
}
