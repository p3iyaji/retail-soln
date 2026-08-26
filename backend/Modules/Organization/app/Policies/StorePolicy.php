<?php

namespace Modules\Organization\Policies;

use App\Models\User;
use Modules\Organization\Models\Store;
use Modules\Organization\Support\OrganizationAuthorization;

class StorePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Store $store): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return OrganizationAuthorization::canManage($user);
    }

    public function update(User $user, Store $store): bool
    {
        return OrganizationAuthorization::canManage($user);
    }

    public function delete(User $user, Store $store): bool
    {
        return OrganizationAuthorization::canManage($user);
    }
}
