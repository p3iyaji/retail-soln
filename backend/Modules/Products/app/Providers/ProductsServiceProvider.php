<?php

namespace Modules\Products\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Audit\Observers\AuditableObserver;
use Modules\Products\Models\Brand;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\Supplier;
use Modules\Products\Models\Unit;
use Modules\Products\Policies\ProductPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ProductsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Products';

    protected string $nameLower = 'products';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        Gate::policy(Product::class, ProductPolicy::class);

        Product::observe(AuditableObserver::class);
        Category::observe(AuditableObserver::class);
        Brand::observe(AuditableObserver::class);
        Unit::observe(AuditableObserver::class);
        Supplier::observe(AuditableObserver::class);
    }
}
