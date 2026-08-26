<?php

namespace Modules\Organization\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Organization\Models\Warehouse;
use Modules\Organization\Policies\OrganizationPolicy;
use Modules\Organization\Policies\StorePolicy;
use Modules\Organization\Policies\WarehousePolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class OrganizationServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Organization';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'organization';

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Store::class, StorePolicy::class);
        Gate::policy(Warehouse::class, WarehousePolicy::class);
    }
}
