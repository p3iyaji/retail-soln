<?php

namespace Modules\Products\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Products\Models\ProductVariantImage */
class ProductVariantImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'altText' => $this->alt_text,
            'sortOrder' => $this->sort_order,
            'isPrimary' => $this->is_primary,
        ];
    }
}
