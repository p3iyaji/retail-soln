<?php

namespace Modules\Inventory\Providers;

use Modules\Audit\Observers\AuditableObserver;
use Modules\Inventory\Console\CheckLowStockCommand;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Models\StockCountItem;
use Modules\Inventory\Models\StockTransfer;
use Modules\Inventory\Models\StockTransferItem;
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

        $this->commands([
            CheckLowStockCommand::class,
        ]);

        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        InventoryBalance::observe(AuditableObserver::class);
        InventoryMovement::observe(AuditableObserver::class);
        StockAdjustment::observe(AuditableObserver::class);
        StockCount::observe(AuditableObserver::class);
        StockCountItem::observe(AuditableObserver::class);
        StockTransfer::observe(AuditableObserver::class);
        StockTransferItem::observe(AuditableObserver::class);
    }
}
