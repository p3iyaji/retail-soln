<?php

namespace Modules\Attendance\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Attendance\Models\ClockLocation;
use Modules\Attendance\Models\ClockShift;
use Modules\Attendance\Policies\ClockLocationPolicy;
use Modules\Attendance\Policies\ClockShiftPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AttendanceServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Attendance';

    protected string $nameLower = 'attendance';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        Gate::policy(ClockLocation::class, ClockLocationPolicy::class);
        Gate::policy(ClockShift::class, ClockShiftPolicy::class);
    }
}
