<?php

namespace Modules\Audit\Providers;

use App\Models\User;
use Modules\Audit\Observers\AuditableObserver;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Organization\Models\Warehouse;
use Modules\Settings\Models\Setting;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AuditServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Audit';

    protected string $nameLower = 'audit';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        Organization::observe(AuditableObserver::class);
        Store::observe(AuditableObserver::class);
        Warehouse::observe(AuditableObserver::class);
        Setting::observe(AuditableObserver::class);
        User::observe(AuditableObserver::class);
    }
}
