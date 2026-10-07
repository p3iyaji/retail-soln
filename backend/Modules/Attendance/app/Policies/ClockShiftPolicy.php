<?php

namespace Modules\Attendance\Policies;

use App\Models\User;
use Modules\Attendance\Models\ClockShift;

class ClockShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance.manage')
            || $user->hasPermission('attendance.clock');
    }

    public function clock(User $user): bool
    {
        return $user->hasPermission('attendance.clock');
    }

    public function void(User $user, ClockShift $shift): bool
    {
        $location = $shift->location;

        if ($location === null) {
            return false;
        }

        return $location->viewerManages($user);
    }
}
