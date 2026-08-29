<?php

namespace Modules\CustomerPortal\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Nwidart\Modules\Support\ModuleServiceProvider;

class CustomerPortalServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'CustomerPortal';

    protected string $nameLower = 'customerportal';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(
            module_path($this->name, 'config/config.php'),
            'customer_portal',
        );
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        RateLimiter::for('portal-auth', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input('email', '')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}
