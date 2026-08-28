<?php

namespace Modules\Sales\Providers;

use Modules\Audit\Observers\AuditableObserver;
use Modules\Sales\Models\CashierSession;
use Modules\Sales\Models\CashMovement;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\InvoiceItem;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleItem;
use Modules\Sales\Models\SalePayment;
use Modules\Sales\Models\Terminal;
use Nwidart\Modules\Support\ModuleServiceProvider;

class SalesServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Sales';

    protected string $nameLower = 'sales';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        Sale::observe(AuditableObserver::class);
        SaleItem::observe(AuditableObserver::class);
        SalePayment::observe(AuditableObserver::class);
        Invoice::observe(AuditableObserver::class);
        InvoiceItem::observe(AuditableObserver::class);
        CashierSession::observe(AuditableObserver::class);
        CashMovement::observe(AuditableObserver::class);
        Terminal::observe(AuditableObserver::class);
    }
}
