<?php

namespace Modules\Organization\Policies;

use App\Models\User;
use Modules\Organization\Models\Organization;

class OrganizationPolicy
{
    public function view(User $user, ?Organization $organization = null): bool
    {
        return $user->hasPermission('org.view');
    }

    public function update(User $user, ?Organization $organization = null): bool
    {
        return $user->hasPermission('org.update');
    }
}
