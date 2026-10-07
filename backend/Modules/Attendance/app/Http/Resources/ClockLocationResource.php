<?php

namespace Modules\Attendance\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Attendance\Models\ClockLocation;
use Modules\Attendance\Models\ClockLocationAssignment;

/** @mixin ClockLocation */
class ClockLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ClockLocation $location */
        $location = $this->resource;
        $viewer = $request->user();
        $canManage = $viewer instanceof User && $location->viewerManages($viewer);
        $mine = $location->assignments->first(
            fn (ClockLocationAssignment $assignment) => $viewer instanceof User
                && (int) $assignment->user_id === (int) $viewer->id
                && $assignment->is_active,
        );

        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'storeId' => $this->store_id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'radiusMeters' => $this->radius_meters,
            'isActive' => (bool) $this->is_active,
            'canManage' => $canManage,
            'requiresGeofence' => $location->requiresGeofence(),
            'myAssignment' => $mine === null ? null : [
                'id' => $mine->id,
                'hourlyRate' => $mine->hourly_rate,
                'isActive' => true,
            ],
            'assignments' => $canManage
                ? $location->assignments->map(fn (ClockLocationAssignment $assignment) => [
                    'id' => $assignment->id,
                    'userId' => $assignment->user_id,
                    'userName' => $assignment->user?->name,
                    'hourlyRate' => $assignment->hourly_rate,
                    'isActive' => (bool) $assignment->is_active,
                ])->values()->all()
                : [],
        ];
    }
}
