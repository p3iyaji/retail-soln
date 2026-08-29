<?php

namespace App\Http\Middleware;

use App\Support\Observability\PrometheusRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordRequestMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('observability.metrics.enabled')) {
            return $next($request);
        }

        $response = $next($request);

        $route = $request->route()?->getName() ?? $request->path();
        $labels = [
            'method' => $request->method(),
            'route' => $route,
            'status' => (string) $response->getStatusCode(),
        ];

        PrometheusRegistry::increment('http_requests_total', $labels);

        return $response;
    }
}
