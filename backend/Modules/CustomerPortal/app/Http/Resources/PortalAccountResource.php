<?php

namespace Modules\CustomerPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Customers\Http\Resources\CustomerAddressResource;

/** @mixin \Modules\CustomerPortal\Models\CustomerAccount */
class PortalAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $customer = $this->customer;

        return [
            'id' => $this->id,
            'email' => $this->email,
            'emailVerified' => $this->hasVerifiedEmail(),
            'emailVerifiedAt' => $this->email_verified_at?->toIso8601String(),
            'customer' => $customer === null ? null : [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'type' => $customer->type?->value ?? $customer->type,
                'accountNumber' => $customer->account_number,
                'addresses' => CustomerAddressResource::collection($customer->addresses ?? collect()),
            ],
        ];
    }
}
