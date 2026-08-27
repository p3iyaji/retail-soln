<?php

namespace Modules\Inventory\Providers;

use Modules\Audit\Observers\AuditableObserver;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Inventory\Models\InventoryMovement;
use Nwidart\Modules\Support\ModuleServiceProvider;

class InventoryServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Inventory';

    protected string $nameLower = 'inventory';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        InventoryBalance::observe(AuditableObserver::class);
        InventoryMovement::observe(AuditableObserver::class);
    }
}
