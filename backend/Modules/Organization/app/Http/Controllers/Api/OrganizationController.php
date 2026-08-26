<?php

namespace Modules\Organization\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Http\Requests\UpdateOrganizationRequest;
use Modules\Organization\Http\Resources\OrganizationResource;
use Modules\Organization\Models\Organization;
use Modules\Organization\Services\OrganizationService;

class OrganizationController
{
    public function show(Request $request, OrganizationService $organizations): JsonResponse
    {
        $organization = $organizations->current();

        $this->authorizeView($request, $organization);

        return response()->json([
            'data' => new OrganizationResource($organization),
        ]);
    }

    public function update(
        UpdateOrganizationRequest $request,
        OrganizationService $organizations,
    ): JsonResponse {
        $organization = $organizations->current();
        $this->authorizeUpdate($request, $organization);

        $attributes = [];
        $validated = $request->validated();

        if (array_key_exists('name', $validated)) {
            $attributes['name'] = $validated['name'];
        }
        if (array_key_exists('legalName', $validated)) {
            $attributes['legal_name'] = $validated['legalName'];
        }
        if (array_key_exists('defaultCurrency', $validated)) {
            $attributes['default_currency'] = strtoupper($validated['defaultCurrency']);
        }
        if (array_key_exists('timezone', $validated)) {
            $attributes['timezone'] = $validated['timezone'];
        }

        $organization = $organizations->updateCurrent($attributes);

        return response()->json([
            'data' => new OrganizationResource($organization),
        ]);
    }

    private function authorizeView(Request $request, Organization $organization): void
    {
        if ($request->user()?->can('view', $organization) !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }

    private function authorizeUpdate(Request $request, Organization $organization): void
    {
        if ($request->user()?->can('update', $organization) !== true) {
            abort(403, 'This action is unauthorized.');
        }
    }
}
