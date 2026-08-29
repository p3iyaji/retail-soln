<?php

namespace Modules\Sync\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class SyncServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Sync';

    protected string $nameLower = 'sync';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(
            module_path($this->name, 'config/config.php'),
            'sync',
        );
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));
    }
}
