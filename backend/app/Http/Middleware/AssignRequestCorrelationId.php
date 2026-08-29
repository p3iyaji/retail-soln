<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Propagates a correlation id for structured logs and downstream support tickets.
 */
class AssignRequestCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = trim((string) $request->header('X-Request-Id', ''));
        if ($requestId === '') {
            $requestId = (string) Str::uuid();
        }

        $request->attributes->set('request_id', $requestId);
        Log::shareContext(['request_id' => $requestId]);

        if (function_exists('Sentry\\configureScope') && config('sentry.dsn')) {
            \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($requestId): void {
                $scope->setTag('request_id', $requestId);
            });
        }

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
