<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Sales\Models\CashMovement */
class CashMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sessionId' => $this->session_id,
            'type' => $this->type?->value ?? $this->type,
            'amount' => $this->amount,
            'referenceType' => $this->reference_type,
            'referenceId' => $this->reference_id,
            'userId' => $this->user_id,
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
