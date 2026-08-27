<?php

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Purchasing\Models\Supplier */
class SupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizationId' => $this->organization_id,
            'name' => $this->name,
            'code' => $this->code,
            'isActive' => $this->is_active,
            'contacts' => SupplierContactResource::collection($this->whenLoaded('contacts')),
            'addresses' => SupplierAddressResource::collection($this->whenLoaded('addresses')),
            'productMappings' => SupplierProductResource::collection($this->whenLoaded('productMappings')),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
