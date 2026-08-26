<?php

namespace Modules\Organization\Policies;

use App\Models\User;
use Modules\Organization\Models\Warehouse;

class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('warehouses.view');
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        if (! $user->hasPermission('warehouses.view')) {
            return false;
        }

        if ($warehouse->store_id === null) {
            return $user->isOrgWide() || $user->hasPermission('warehouses.manage');
        }

        return $user->canAccessStore($warehouse->store_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('warehouses.manage');
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $user->hasPermission('warehouses.manage');
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        return $user->hasPermission('warehouses.manage');
    }
}
