<?php

namespace Modules\CustomerPortal\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Organization\Models\Organization;

class PortalOrganizationResolver
{
    public function resolve(): Organization
    {
        $configuredId = config('customer_portal.organization_id');

        if ($configuredId !== null && $configuredId !== '') {
            $organization = Organization::query()->find((int) $configuredId);

            if ($organization === null) {
                throw (new ModelNotFoundException)->setModel(Organization::class, [(int) $configuredId]);
            }

            return $organization;
        }

        $organization = Organization::query()->orderBy('id')->first();

        if ($organization === null) {
            throw (new ModelNotFoundException)->setModel(Organization::class);
        }

        return $organization;
    }
}
