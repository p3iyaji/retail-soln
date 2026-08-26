<?php

namespace Modules\Settings\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Organization\Services\OrganizationService;
use Modules\Settings\Models\Setting;

class SettingController
{
    public function index(Request $request, OrganizationService $organizations): JsonResponse
    {
        if (! $request->user()->hasPermission('settings.manage')) {
            abort(403, 'This action is unauthorized.');
        }

        $organization = $organizations->current();
        $storeId = $request->filled('storeId') ? (int) $request->integer('storeId') : null;

        $query = Setting::query()
            ->where('organization_id', $organization->id)
            ->orderBy('key');

        if ($storeId !== null) {
            $query->where('store_id', $storeId);
        } elseif ($request->boolean('orgOnly')) {
            $query->whereNull('store_id');
        }

        $settings = $query->get();

        return response()->json([
            'data' => $settings->map(fn (Setting $setting) => [
                'id' => $setting->id,
                'organizationId' => $setting->organization_id,
                'storeId' => $setting->store_id,
                'key' => $setting->key,
                'value' => $setting->value,
                'updatedAt' => $setting->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    public function upsert(Request $request, OrganizationService $organizations): JsonResponse
    {
        if (! $request->user()->hasPermission('settings.manage')) {
            abort(403, 'This action is unauthorized.');
        }

        $organization = $organizations->current();

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'value' => ['nullable'],
            'storeId' => [
                'nullable',
                'integer',
                Rule::exists('stores', 'id')->where(
                    fn ($q) => $q->where('organization_id', $organization->id)
                ),
            ],
        ]);

        $storeId = $validated['storeId'] ?? null;

        $setting = Setting::query()
            ->where('organization_id', $organization->id)
            ->where('key', $validated['key'])
            ->when(
                $storeId === null,
                fn ($q) => $q->whereNull('store_id'),
                fn ($q) => $q->where('store_id', $storeId),
            )
            ->first();

        if ($setting === null) {
            $setting = Setting::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $storeId,
                'key' => $validated['key'],
                'value' => $this->normalizeValue($validated['value'] ?? null),
            ]);
        } else {
            $setting->value = $this->normalizeValue($validated['value'] ?? null);
            $setting->save();
        }

        return response()->json([
            'data' => [
                'id' => $setting->id,
                'organizationId' => $setting->organization_id,
                'storeId' => $setting->store_id,
                'key' => $setting->key,
                'value' => $setting->value,
                'updatedAt' => $setting->updated_at?->toIso8601String(),
            ],
        ]);
    }

    private function normalizeValue(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        return ['data' => $value];
    }
}
