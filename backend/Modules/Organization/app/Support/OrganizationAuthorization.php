<?php

namespace Modules\Organization\Support;

use App\Models\User;

final class OrganizationAuthorization
{
    public static function canManage(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasPermission('org.update')
            || $user->hasPermission('stores.create');
    }

    public static function can(string $permission, ?User $user): bool
    {
        return $user !== null && $user->hasPermission($permission);
    }
}
