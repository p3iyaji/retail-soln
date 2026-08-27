<?php

namespace Modules\Purchasing\Providers;

use Modules\Audit\Observers\AuditableObserver;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Models\SupplierAddress;
use Modules\Purchasing\Models\SupplierContact;
use Modules\Purchasing\Models\SupplierProduct;
use Nwidart\Modules\Support\ModuleServiceProvider;

class PurchasingServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Purchasing';

    protected string $nameLower = 'purchasing';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        Supplier::observe(AuditableObserver::class);
        SupplierContact::observe(AuditableObserver::class);
        SupplierAddress::observe(AuditableObserver::class);
        SupplierProduct::observe(AuditableObserver::class);
    }
}
