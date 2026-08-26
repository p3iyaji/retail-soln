<?php

namespace Modules\Organization\Services;

use Modules\Organization\Models\Organization;

class OrganizationService
{
    /**
     * Single-organization-per-deployment: return the current org or create a default.
     */
    public function current(): Organization
    {
        $organization = Organization::query()->orderBy('id')->first();

        if ($organization !== null) {
            return $organization;
        }

        return Organization::query()->create([
            'name' => config('app.name', 'Retail Soln'),
            'legal_name' => null,
            'default_currency' => 'USD',
            'timezone' => 'UTC',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateCurrent(array $attributes): Organization
    {
        $organization = $this->current();
        $organization->fill($attributes);
        $organization->save();

        return $organization->fresh();
    }
}
