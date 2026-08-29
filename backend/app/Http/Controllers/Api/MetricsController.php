<?php

namespace App\Http\Controllers\Api;

use App\Support\Observability\PrometheusRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MetricsController
{
    public function __invoke(Request $request): Response
    {
        if (! config('observability.metrics.enabled')) {
            abort(404);
        }

        $token = (string) config('observability.metrics.token', '');
        if ($token !== '') {
            $auth = (string) $request->bearerToken();
            if (! hash_equals($token, $auth)) {
                abort(401, 'Invalid metrics token.');
            }
        }

        return response(PrometheusRegistry::render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
