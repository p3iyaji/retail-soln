<?php

namespace Modules\CustomerPortal\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\CustomerPortal\Console\ExpireUnpaidPortalReservationsCommand;
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

        $this->commands([
            ExpireUnpaidPortalReservationsCommand::class,
        ]);

        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        RateLimiter::for('portal-auth', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input('email', '')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('portal-catalogue', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('portal-cart', function (Request $request) {
            $user = $request->user();
            $key = $user?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(60)->by('portal-cart|'.$key);
        });

        RateLimiter::for('portal-returns', function (Request $request) {
            $user = $request->user();
            $key = $user?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(30)->by('portal-returns|'.$key);
        });

        RateLimiter::for('portal-contact', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input('email', '')));

            return Limit::perMinute(5)->by('portal-contact|'.$email.'|'.$request->ip());
        });

        RateLimiter::for('portal-chat', function (Request $request) {
            $user = $request->user();
            $key = $user?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(30)->by('portal-chat|'.$key);
        });
    }
}
