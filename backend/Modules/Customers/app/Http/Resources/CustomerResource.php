<?php

namespace Modules\Customers\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Customers\Enums\CustomerType;

/** @mixin \Modules\Customers\Models\Customer */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organizationId' => $this->organization_id,
            'customerGroupId' => $this->customer_group_id,
            'type' => $this->type instanceof CustomerType ? $this->type->value : $this->type,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'accountNumber' => $this->account_number,
            'isWalkIn' => $this->is_walk_in,
            'creditLimit' => $this->credit_limit,
            'balance' => $this->balance,
            'loyaltyRef' => $this->loyalty_ref,
            'notes' => $this->notes,
            'isActive' => $this->is_active,
            'group' => new CustomerGroupResource($this->whenLoaded('group')),
            'addresses' => CustomerAddressResource::collection($this->whenLoaded('addresses')),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
