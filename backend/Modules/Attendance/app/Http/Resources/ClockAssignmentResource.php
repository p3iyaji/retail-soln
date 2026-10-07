<?php

namespace Modules\Attendance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Attendance\Models\ClockLocationAssignment;

/** @mixin ClockLocationAssignment */
class ClockAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'locationId' => $this->location_id,
            'userId' => $this->user_id,
            'userName' => $this->user?->name,
            'hourlyRate' => $this->hourly_rate,
            'isActive' => (bool) $this->is_active,
        ];
    }
}
