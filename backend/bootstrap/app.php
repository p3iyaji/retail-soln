<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TLS terminates at nginx in the v1 stack, so X-Forwarded-* must be honoured
        // for HTTPS detection and for throttle keys to see the real client IP.
        $middleware->trustProxies(at: '*');

        $middleware->throttleApi();

        $middleware->api(prepend: [
            \App\Http\Middleware\AssignRequestCorrelationId::class,
            \App\Http\Middleware\RecordRequestMetrics::class,
        ]);

        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        $middleware->alias([
            'staff' => \App\Http\Middleware\EnsureStaffUser::class,
            'portal' => \App\Http\Middleware\EnsurePortalAccount::class,
            'portal.verified' => \App\Http\Middleware\EnsurePortalEmailVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
