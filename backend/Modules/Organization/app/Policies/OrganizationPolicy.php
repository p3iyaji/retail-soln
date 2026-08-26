<?php

namespace Modules\Organization\Policies;

use App\Models\User;
use Modules\Organization\Models\Organization;
use Modules\Organization\Support\OrganizationAuthorization;

class OrganizationPolicy
{
    public function view(User $user, ?Organization $organization = null): bool
    {
        return $user !== null;
    }

    public function update(User $user, ?Organization $organization = null): bool
    {
        return OrganizationAuthorization::canManage($user);
    }
}
