<?php

namespace Modules\Attendance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Attendance\Models\ClockShift;

/** @mixin ClockShift */
class ClockShiftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'userName' => $this->user?->name,
            'locationId' => $this->location_id,
            'locationName' => $this->location?->name,
            'hourlyRate' => $this->hourly_rate,
            'clockInAt' => $this->clock_in_at?->toIso8601String(),
            'clockOutAt' => $this->clock_out_at?->toIso8601String(),
            'durationMinutes' => $this->duration_minutes,
            'grossPay' => $this->gross_pay,
            'voidedAt' => $this->voided_at?->toIso8601String(),
        ];
    }
}
