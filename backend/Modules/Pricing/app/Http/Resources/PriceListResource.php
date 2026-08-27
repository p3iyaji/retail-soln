<?php

namespace Modules\Pricing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Pricing\Models\PriceList */
class PriceListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizationId' => $this->organization_id,
            'storeId' => $this->store_id,
            'name' => $this->name,
            'isActive' => $this->is_active,
            'isDefault' => $this->is_default,
            'prices' => PriceResource::collection($this->whenLoaded('prices')),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
