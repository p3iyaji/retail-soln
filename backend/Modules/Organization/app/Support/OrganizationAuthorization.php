<?php

namespace Modules\Organization\Support;

use App\Models\User;

final class OrganizationAuthorization
{
    /**
     * Privileged roles until Story 1.4 seeds the full permission matrix.
     *
     * @var list<string>
     */
    public const PRIVILEGED_ROLES = [
        'super_admin',
        'org_admin',
    ];

    public static function canManage(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return in_array($user->role, self::PRIVILEGED_ROLES, true);
    }
}
