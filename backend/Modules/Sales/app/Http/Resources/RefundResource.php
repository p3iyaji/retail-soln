<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Sales\Models\Refund */
class RefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method,
            'provider' => $this->provider,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
