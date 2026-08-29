<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transport hardening for the API surface (NFR-6). HSTS is only emitted in
 * production so local HTTP development is not pinned to HTTPS by the browser.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', (string) config('security.headers.frame_options', 'DENY'));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set(
            'Referrer-Policy',
            (string) config('security.headers.referrer_policy', 'strict-origin-when-cross-origin'),
        );

        if (app()->environment('production')) {
            $maxAge = (int) config('security.headers.hsts_max_age', 31536000);
            $response->headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        }

        return $response;
    }
}
