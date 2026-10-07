<?php

namespace Modules\Attendance\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Attendance\Http\Resources\ClockAssignmentResource;
use Modules\Attendance\Http\Resources\ClockLocationResource;
use Modules\Attendance\Models\ClockLocation;
use Modules\Attendance\Models\ClockLocationAssignment;
use Modules\Attendance\Services\AttendanceRuleException;
use Modules\Attendance\Services\AttendanceService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;

class ClockLocationController
{
    public function index(
        Request $request,
        OrganizationService $organizations,
        AttendanceService $service,
    ): JsonResponse {
        $this->authorize($request, 'viewAny', ClockLocation::class);

        $locations = $service->locationsFor($request->user(), $organizations->current()->id);

        return response()->json([
            'data' => ClockLocationResource::collection($locations),
        ]);
    }

    public function store(
        Request $request,
        OrganizationService $organizations,
        AttendanceService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'storeId' => ['nullable', 'integer', 'exists:stores,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radiusMeters' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $name = $this->requiredName($validated['name']);
        $orgId = $organizations->current()->id;
        $storeId = isset($validated['storeId']) ? (int) $validated['storeId'] : null;
        $this->assertStoreInOrganization($storeId, $orgId);
        $this->authorize($request, 'create', [ClockLocation::class, $storeId]);

        $location = $service->createLocation($orgId, [
            'name' => $name,
            'address' => $validated['address'] ?? null,
            'store_id' => $storeId,
            'latitude' => $this->coordinate($validated['latitude'] ?? null),
            'longitude' => $this->coordinate($validated['longitude'] ?? null),
            'radius_meters' => $validated['radiusMeters'] ?? null,
            'is_active' => (bool) ($validated['isActive'] ?? true),
        ]);

        return response()->json([
            'data' => new ClockLocationResource($location),
        ], 201);
    }

    public function show(Request $request, ClockLocation $location, OrganizationService $organizations): JsonResponse
    {
        $this->assertSameOrganization($location, $organizations->current()->id);
        $this->authorize($request, 'view', $location);

        $location->load(['assignments.user']);

        return response()->json([
            'data' => new ClockLocationResource($location),
        ]);
    }

    public function update(
        Request $request,
        ClockLocation $location,
        OrganizationService $organizations,
        AttendanceService $service,
    ): JsonResponse {
        $this->assertSameOrganization($location, $organizations->current()->id);
        $this->authorize($request, 'update', $location);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'storeId' => ['sometimes', 'nullable', 'integer', 'exists:stores,id'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'radiusMeters' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $attributes = [];
        if (array_key_exists('name', $validated)) {
            $attributes['name'] = $this->requiredName($validated['name']);
        }
        if (array_key_exists('address', $validated)) {
            $attributes['address'] = $validated['address'];
        }
        if (array_key_exists('storeId', $validated)) {
            $storeId = $validated['storeId'] !== null ? (int) $validated['storeId'] : null;
            $this->assertStoreInOrganization($storeId, $organizations->current()->id);
            $this->authorize($request, 'create', [ClockLocation::class, $storeId]);
            $attributes['store_id'] = $storeId;
        }
        if (array_key_exists('latitude', $validated)) {
            $attributes['latitude'] = $this->coordinate($validated['latitude']);
        }
        if (array_key_exists('longitude', $validated)) {
            $attributes['longitude'] = $this->coordinate($validated['longitude']);
        }
        if (array_key_exists('radiusMeters', $validated)) {
            $attributes['radius_meters'] = $validated['radiusMeters'];
        }
        if (array_key_exists('isActive', $validated)) {
            $attributes['is_active'] = (bool) $validated['isActive'];
        }

        $updated = $service->updateLocation($location, $attributes);

        return response()->json([
            'data' => new ClockLocationResource($updated),
        ]);
    }

    public function destroy(
        Request $request,
        ClockLocation $location,
        OrganizationService $organizations,
        AttendanceService $service,
    ): JsonResponse {
        $this->assertSameOrganization($location, $organizations->current()->id);
        $this->authorize($request, 'delete', $location);

        try {
            $service->deleteLocation($location);
        } catch (AttendanceRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Location deleted.',
        ]);
    }

    public function assign(
        Request $request,
        ClockLocation $location,
        OrganizationService $organizations,
        AttendanceService $service,
    ): JsonResponse {
        $this->assertSameOrganization($location, $organizations->current()->id);
        $this->authorize($request, 'assign', $location);

        $validated = $request->validate([
            'userId' => ['required', 'integer', 'exists:users,id'],
            'hourlyRate' => ['required', 'regex:/^\d{1,11}(\.\d{1,4})?$/'],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $existing = ClockLocationAssignment::query()
            ->where('location_id', $location->id)
            ->where('user_id', (int) $validated['userId'])
            ->first();

        if (array_key_exists('isActive', $validated)) {
            $isActive = (bool) $validated['isActive'];
        } elseif ($existing !== null) {
            $isActive = (bool) $existing->is_active;
        } else {
            $isActive = true;
        }

        $assignment = $service->upsertAssignment(
            $location,
            (int) $validated['userId'],
            (string) $validated['hourlyRate'],
            $isActive,
        );

        return response()->json([
            'data' => new ClockAssignmentResource($assignment),
        ]);
    }

    private function authorize(Request $request, string $ability, mixed $model): void
    {
        $user = $request->user();
        if (! $user instanceof User || $user->can($ability, $model) !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function assertSameOrganization(ClockLocation $location, int $organizationId): void
    {
        if ((int) $location->organization_id !== $organizationId) {
            abort(404);
        }
    }

    private function assertStoreInOrganization(?int $storeId, int $organizationId): void
    {
        if ($storeId === null) {
            return;
        }

        $exists = Store::query()
            ->where('organization_id', $organizationId)
            ->whereKey($storeId)
            ->exists();

        if (! $exists) {
            abort(422, 'Store not found.');
        }
    }

    private function requiredName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'name' => ['The name field is required.'],
            ]);
        }

        return $trimmed;
    }

    private function coordinate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $plain = sprintf('%.7F', (float) $value);

        return bcadd($plain, '0', 7);
    }
}
