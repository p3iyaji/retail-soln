<?php

namespace Modules\Organization\Policies;

use App\Models\User;
use Modules\Organization\Models\Warehouse;
use Modules\Organization\Support\OrganizationAuthorization;

class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return OrganizationAuthorization::canManage($user);
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return OrganizationAuthorization::canManage($user);
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        return OrganizationAuthorization::canManage($user);
    }
}
