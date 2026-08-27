<?php

namespace Modules\Pricing\Providers;

use Modules\Audit\Observers\AuditableObserver;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceList;
use Modules\Pricing\Models\Promotion;
use Modules\Pricing\Models\PromotionRule;
use Nwidart\Modules\Support\ModuleServiceProvider;

class PricingServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Pricing';

    protected string $nameLower = 'pricing';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        PriceList::observe(AuditableObserver::class);
        Price::observe(AuditableObserver::class);
        Promotion::observe(AuditableObserver::class);
        PromotionRule::observe(AuditableObserver::class);
    }
}
