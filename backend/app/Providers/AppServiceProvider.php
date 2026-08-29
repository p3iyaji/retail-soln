<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        $this->registerRateLimiters();
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('security.rate_limits.api', 120))
                ->by($request->user()?->id ?: $request->ip());
        });

        // Provider callbacks are unauthenticated, so the only stable key is the caller IP.
        RateLimiter::for('payment-webhooks', function (Request $request) {
            return Limit::perMinute((int) config('security.rate_limits.payment_webhooks', 60))
                ->by($request->ip());
        });
    }
}
