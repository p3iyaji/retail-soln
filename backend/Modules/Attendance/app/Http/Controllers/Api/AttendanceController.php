<?php

namespace Modules\Attendance\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Attendance\Http\Resources\ClockShiftResource;
use Modules\Attendance\Models\ClockLocation;
use Modules\Attendance\Models\ClockShift;
use Modules\Attendance\Services\AttendanceRuleException;
use Modules\Attendance\Services\AttendanceService;
use Modules\Organization\Services\OrganizationService;

class AttendanceController
{
    public function clockIn(
        Request $request,
        OrganizationService $organizations,
        AttendanceService $service,
    ): JsonResponse {
        $this->authorize($request, 'clock', ClockShift::class);

        $validated = $request->validate([
            'locationId' => ['required', 'integer'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $location = ClockLocation::query()
            ->where('organization_id', $organizations->current()->id)
            ->whereKey((int) $validated['locationId'])
            ->first();

        if ($location === null) {
            abort(404);
        }

        try {
            $shift = $service->clockIn(
                $request->user(),
                $location,
                isset($validated['latitude']) ? (string) $validated['latitude'] : null,
                isset($validated['longitude']) ? (string) $validated['longitude'] : null,
            );
        } catch (AttendanceRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new ClockShiftResource($shift),
        ], 201);
    }

    public function clockOut(
        Request $request,
        AttendanceService $service,
    ): JsonResponse {
        $this->authorize($request, 'clock', ClockShift::class);

        try {
            $shift = $service->clockOut($request->user());
        } catch (AttendanceRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new ClockShiftResource($shift),
        ]);
    }

    public function shifts(
        Request $request,
        OrganizationService $organizations,
        AttendanceService $service,
    ): JsonResponse {
        $this->authorize($request, 'viewAny', ClockShift::class);

        $validated = $request->validate([
            'mine' => ['sometimes', 'boolean'],
            'userId' => ['sometimes', 'nullable', 'integer'],
            'locationId' => ['sometimes', 'nullable', 'integer'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'open' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $paginator = $service->shifts(
            $request->user(),
            $organizations->current()->id,
            $this->filters($request, $validated),
            $perPage,
        );

        return response()->json([
            'data' => ClockShiftResource::collection($paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function summary(
        Request $request,
        OrganizationService $organizations,
        AttendanceService $service,
    ): JsonResponse {
        $this->authorize($request, 'viewAny', ClockShift::class);

        $validated = $request->validate([
            'mine' => ['sometimes', 'boolean'],
            'userId' => ['sometimes', 'nullable', 'integer'],
            'locationId' => ['sometimes', 'nullable', 'integer'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $filters = $this->filters($request, $validated);
        unset($filters['open']);

        return response()->json([
            'data' => $service->summary(
                $request->user(),
                $organizations->current()->id,
                $filters,
            ),
        ]);
    }

    public function void(
        Request $request,
        ClockShift $shift,
        OrganizationService $organizations,
        AttendanceService $service,
    ): JsonResponse {
        $shift->load('location');
        if ($shift->location === null || (int) $shift->location->organization_id !== $organizations->current()->id) {
            abort(404);
        }

        $this->authorize($request, 'void', $shift);

        try {
            $voided = $service->voidShift($shift);
        } catch (AttendanceRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new ClockShiftResource($voided),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{mine?: bool, user_id?: ?int, location_id?: ?int, from?: ?string, to?: ?string, open?: bool}
     */
    private function filters(Request $request, array $validated): array
    {
        return [
            'mine' => $request->boolean('mine'),
            'user_id' => isset($validated['userId']) ? (int) $validated['userId'] : null,
            'location_id' => isset($validated['locationId']) ? (int) $validated['locationId'] : null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'open' => $request->boolean('open'),
        ];
    }

    private function authorize(Request $request, string $ability, mixed $model): void
    {
        $user = $request->user();
        if (! $user instanceof User || $user->can($ability, $model) !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }
}
