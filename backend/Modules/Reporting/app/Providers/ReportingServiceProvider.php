<?php

namespace Modules\Reporting\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class ReportingServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Reporting';

    protected string $nameLower = 'reporting';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
