<?php

namespace Modules\Organization\Policies;

use App\Models\User;
use Modules\Organization\Models\Store;

class StorePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('stores.view');
    }

    public function view(User $user, Store $store): bool
    {
        return $user->hasPermission('stores.view')
            && $user->canAccessStore($store->id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('stores.create');
    }

    public function update(User $user, Store $store): bool
    {
        return $user->hasPermission('stores.update')
            && $user->canAccessStore($store->id);
    }

    public function delete(User $user, Store $store): bool
    {
        return $user->hasPermission('stores.create');
    }
}
