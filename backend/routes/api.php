<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', [HealthController::class, 'live'])
        ->withoutMiddleware('throttle:api');
    Route::get('/health/ready', [HealthController::class, 'ready'])
        ->withoutMiddleware('throttle:api');
    Route::get('/metrics', MetricsController::class)
        ->withoutMiddleware('throttle:api');

    Route::prefix('webhooks/payments')
        ->middleware('throttle:payment-webhooks')
        ->group(function (): void {
            Route::post('/stripe', [PaymentWebhookController::class, 'stripe']);
            Route::post('/paystack', [PaymentWebhookController::class, 'paystack']);
            Route::post('/manual', [PaymentWebhookController::class, 'manual']);
        });
});
