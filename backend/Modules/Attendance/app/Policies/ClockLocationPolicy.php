<?php

namespace Modules\Attendance\Policies;

use App\Models\User;
use Modules\Attendance\Models\ClockLocation;

class ClockLocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance.manage')
            || $user->hasPermission('attendance.clock');
    }

    public function view(User $user, ClockLocation $location): bool
    {
        if ($location->viewerManages($user)) {
            return true;
        }

        if (! $user->hasPermission('attendance.clock')) {
            return false;
        }

        return $location->assignments()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }

    public function create(User $user, ?int $storeId = null): bool
    {
        if (! $user->hasPermission('attendance.manage')) {
            return false;
        }

        if ($storeId === null) {
            return $user->isOrgWide();
        }

        return $user->canAccessStore($storeId);
    }

    public function update(User $user, ClockLocation $location): bool
    {
        return $location->viewerManages($user);
    }

    public function delete(User $user, ClockLocation $location): bool
    {
        return $location->viewerManages($user);
    }

    public function assign(User $user, ClockLocation $location): bool
    {
        return $location->viewerManages($user);
    }
}
