<?php

namespace Modules\Attendance\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Attendance\Models\ClockLocation;
use Modules\Attendance\Models\ClockLocationAssignment;
use Modules\Attendance\Models\ClockShift;
use Modules\Audit\Services\AuditLogger;

class AttendanceService
{
    private const EARTH_RADIUS_METERS = 6371000;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return Collection<int, ClockLocation>
     */
    public function locationsFor(User $user, int $organizationId): Collection
    {
        $managed = collect();
        if ($user->hasPermission('attendance.manage')) {
            $query = ClockLocation::query()
                ->where('organization_id', $organizationId)
                ->with(['assignments.user']);

            if (! $user->isOrgWide()) {
                $query->whereIn('store_id', $user->accessibleStoreIds());
            }

            $managed = $query->orderBy('name')->orderBy('id')->get();
        }

        $assigned = collect();
        if ($user->hasPermission('attendance.clock')) {
            $assigned = ClockLocation::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->whereHas('assignments', function (Builder $query) use ($user): void {
                    $query->where('user_id', $user->id)->where('is_active', true);
                })
                ->with(['assignments.user'])
                ->orderBy('name')
                ->orderBy('id')
                ->get();
        }

        return $managed->concat($assigned)->unique('id')->values();
    }

    /**
     * @param  array{name: string, address: ?string, store_id: ?int, latitude: ?string, longitude: ?string, radius_meters: ?int, is_active: bool}  $attributes
     */
    public function createLocation(int $organizationId, array $attributes): ClockLocation
    {
        $location = ClockLocation::query()->create([
            'organization_id' => $organizationId,
            'store_id' => $attributes['store_id'],
            'name' => $attributes['name'],
            'address' => $attributes['address'],
            'latitude' => $attributes['latitude'],
            'longitude' => $attributes['longitude'],
            'radius_meters' => $attributes['radius_meters'],
            'is_active' => $attributes['is_active'],
        ]);

        $this->audit->record(
            action: 'created',
            model: $location,
            after: $location->attributesToArray(),
            storeId: $location->store_id,
            organizationId: $organizationId,
        );

        return $location->load(['assignments.user']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateLocation(ClockLocation $location, array $attributes): ClockLocation
    {
        $before = $location->attributesToArray();
        $location->fill($attributes);
        $location->save();
        $location->refresh();

        $this->audit->record(
            action: 'updated',
            model: $location,
            before: $before,
            after: $location->attributesToArray(),
            storeId: $location->store_id,
            organizationId: (int) $location->organization_id,
        );

        return $location->load(['assignments.user']);
    }

    public function deleteLocation(ClockLocation $location): void
    {
        if ($location->shifts()->exists()) {
            throw new AttendanceRuleException('This location has shifts and cannot be deleted.');
        }

        $before = $location->attributesToArray();
        $storeId = $location->store_id;
        $organizationId = (int) $location->organization_id;
        $location->delete();

        $this->audit->record(
            action: 'deleted',
            model: $location,
            before: $before,
            storeId: $storeId,
            organizationId: $organizationId,
        );
    }

    public function upsertAssignment(
        ClockLocation $location,
        int $userId,
        string $hourlyRate,
        bool $isActive,
    ): ClockLocationAssignment {
        $existing = ClockLocationAssignment::query()
            ->where('location_id', $location->id)
            ->where('user_id', $userId)
            ->first();

        $before = $existing?->attributesToArray();
        $rate = bcadd($hourlyRate, '0', 4);

        $assignment = ClockLocationAssignment::query()->updateOrCreate(
            [
                'location_id' => $location->id,
                'user_id' => $userId,
            ],
            [
                'hourly_rate' => $rate,
                'is_active' => $isActive,
            ],
        );

        $assignment->refresh()->load('user');

        $this->audit->record(
            action: $before === null ? 'created' : 'updated',
            model: $assignment,
            before: $before,
            after: $assignment->attributesToArray(),
            storeId: $location->store_id,
            organizationId: (int) $location->organization_id,
        );

        return $assignment;
    }

    public function clockIn(User $user, ClockLocation $location, ?string $latitude, ?string $longitude): ClockShift
    {
        return DB::transaction(function () use ($user, $location, $latitude, $longitude): ClockShift {
            if (! $location->is_active) {
                throw new AttendanceRuleException('This location is inactive.');
            }

            $assignment = ClockLocationAssignment::query()
                ->where('location_id', $location->id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            if ($assignment === null) {
                throw new AttendanceRuleException('You are not assigned to this location.');
            }

            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $alreadyIn = ClockShift::query()
                ->where('user_id', $user->id)
                ->whereNull('clock_out_at')
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->exists();

            if ($alreadyIn) {
                throw new AttendanceRuleException('You are already clocked in.');
            }

            $this->assertInsideGeofence($location, $latitude, $longitude);

            $shift = ClockShift::query()->create([
                'user_id' => $user->id,
                'location_id' => $location->id,
                'hourly_rate' => bcadd((string) $assignment->hourly_rate, '0', 4),
                'clock_in_at' => now(),
            ]);

            $shift->load(['user', 'location']);

            $this->audit->record(
                action: 'clock-in',
                model: $shift,
                after: [
                    'location_id' => $shift->location_id,
                    'hourly_rate' => $shift->hourly_rate,
                    'clock_in_at' => $shift->clock_in_at?->toIso8601String(),
                ],
                storeId: $location->store_id,
                organizationId: (int) $location->organization_id,
            );

            return $shift;
        });
    }

    public function clockOut(User $user): ClockShift
    {
        return DB::transaction(function () use ($user): ClockShift {
            $shift = ClockShift::query()
                ->where('user_id', $user->id)
                ->whereNull('clock_out_at')
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->first();

            if ($shift === null) {
                throw new AttendanceRuleException('You are not clocked in.');
            }

            $shift->refresh();
            if ($shift->clock_out_at !== null || $shift->voided_at !== null) {
                throw new AttendanceRuleException('You are not clocked in.');
            }

            $clockOut = now();
            $seconds = $clockOut->getTimestamp() - $shift->clock_in_at->getTimestamp();
            $minutes = intdiv(max(0, $seconds), 60);
            $rate = bcadd((string) $shift->hourly_rate, '0', 4);
            $grossPay = bcmul(bcdiv((string) $minutes, '60', 8), $rate, 4);

            $before = [
                'clock_out_at' => null,
                'duration_minutes' => null,
                'gross_pay' => null,
            ];

            $shift->forceFill([
                'clock_out_at' => $clockOut,
                'duration_minutes' => $minutes,
                'gross_pay' => $grossPay,
            ])->save();

            $shift->refresh()->load(['user', 'location']);

            $this->audit->record(
                action: 'clock-out',
                model: $shift,
                before: $before,
                after: [
                    'clock_out_at' => $shift->clock_out_at?->toIso8601String(),
                    'duration_minutes' => $shift->duration_minutes,
                    'gross_pay' => $shift->gross_pay,
                ],
                storeId: $shift->location?->store_id,
                organizationId: $shift->location?->organization_id,
            );

            return $shift;
        });
    }

    public function voidShift(ClockShift $shift): ClockShift
    {
        return DB::transaction(function () use ($shift): ClockShift {
            $locked = ClockShift::query()->whereKey($shift->id)->lockForUpdate()->first();

            if ($locked === null || $locked->voided_at !== null) {
                throw new AttendanceRuleException('This shift is already voided.');
            }

            $fill = ['voided_at' => now()];
            if ($locked->clock_out_at === null) {
                $fill['clock_out_at'] = now();
            }

            $before = ['voided_at' => null];
            $locked->forceFill($fill)->save();
            $locked->refresh()->load(['user', 'location']);

            $this->audit->record(
                action: 'voided',
                model: $locked,
                before: $before,
                after: [
                    'voided_at' => $locked->voided_at?->toIso8601String(),
                    'clock_out_at' => $locked->clock_out_at?->toIso8601String(),
                ],
                storeId: $locked->location?->store_id,
                organizationId: $locked->location?->organization_id,
            );

            return $locked;
        });
    }

    /**
     * @param  array{mine?: bool, user_id?: ?int, location_id?: ?int, from?: ?string, to?: ?string, open?: bool}  $filters
     * @return LengthAwarePaginator<int, ClockShift>
     */
    public function shifts(User $user, int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->scopedShifts($user, $organizationId, $filters)
            ->with(['user', 'location'])
            ->orderByDesc('clock_in_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{mine?: bool, user_id?: ?int, location_id?: ?int, from?: ?string, to?: ?string}  $filters
     * @return array{durationMinutes: int, hours: string, grossPay: string}
     */
    public function summary(User $user, int $organizationId, array $filters): array
    {
        $rows = $this->scopedShifts($user, $organizationId, $filters)
            ->whereNull('voided_at')
            ->whereNotNull('clock_out_at')
            ->get(['duration_minutes', 'gross_pay']);

        $minutes = 0;
        $pay = '0.0000';
        foreach ($rows as $row) {
            $minutes += (int) $row->duration_minutes;
            $pay = bcadd($pay, (string) $row->gross_pay, 4);
        }

        return [
            'durationMinutes' => $minutes,
            'hours' => bcdiv((string) $minutes, '60', 4),
            'grossPay' => $pay,
        ];
    }

    /**
     * @param  array{mine?: bool, user_id?: ?int, location_id?: ?int, from?: ?string, to?: ?string, open?: bool}  $filters
     * @return Builder<ClockShift>
     */
    private function scopedShifts(User $user, int $organizationId, array $filters): Builder
    {
        $mine = ($filters['mine'] ?? false) || ! $user->hasPermission('attendance.manage');

        $query = ClockShift::query()->whereHas('location', function (Builder $location) use ($user, $organizationId, $mine): void {
            $location->where('organization_id', $organizationId);

            if (! $mine && $user->hasPermission('attendance.manage') && ! $user->isOrgWide()) {
                $location->whereIn('store_id', $user->accessibleStoreIds());
            }
        });

        if ($mine) {
            $query->where('user_id', $user->id);
        } elseif (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('clock_in_at', '>=', $filters['from'].' 00:00:00');
        }

        if (! empty($filters['to'])) {
            $query->where('clock_in_at', '<=', $filters['to'].' 23:59:59');
        }

        if ($filters['open'] ?? false) {
            $query->whereNull('clock_out_at')->whereNull('voided_at');
        }

        return $query;
    }

    private function assertInsideGeofence(ClockLocation $location, ?string $latitude, ?string $longitude): void
    {
        if (! $location->requiresGeofence()) {
            return;
        }

        if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
            throw new AttendanceRuleException('Coordinates are required for this location.');
        }

        $distance = $this->distanceMeters(
            (string) $location->latitude,
            (string) $location->longitude,
            $latitude,
            $longitude,
        );

        if ($distance > (float) $location->radius_meters) {
            throw new AttendanceRuleException('You are outside this location.');
        }
    }

    private function distanceMeters(string $lat1, string $lng1, string $lat2, string $lng2): float
    {
        $phi1 = deg2rad((float) $lat1);
        $phi2 = deg2rad((float) $lat2);
        $deltaPhi = deg2rad((float) $lat2 - (float) $lat1);
        $deltaLambda = deg2rad((float) $lng2 - (float) $lng1);
        $a = sin($deltaPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($deltaLambda / 2) ** 2;
        $a = min(1.0, max(0.0, $a));
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }
}
