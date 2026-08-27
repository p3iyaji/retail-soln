<?php

namespace Modules\Customers\Providers;

use Modules\Audit\Observers\AuditableObserver;
use Modules\Customers\Models\Customer;
use Modules\Customers\Models\CustomerAddress;
use Modules\Customers\Models\CustomerGroup;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CustomersServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Customers';

    protected string $nameLower = 'customers';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        Customer::observe(AuditableObserver::class);
        CustomerAddress::observe(AuditableObserver::class);
        CustomerGroup::observe(AuditableObserver::class);
    }
}
