<?php

use App\Http\Controllers\Api\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Liveness must stay answerable while the rest of the API is being throttled.
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => 'retail-soln',
        ]);
    })->withoutMiddleware('throttle:api');

    Route::prefix('webhooks/payments')
        ->middleware('throttle:payment-webhooks')
        ->group(function (): void {
            Route::post('/stripe', [PaymentWebhookController::class, 'stripe']);
            Route::post('/paystack', [PaymentWebhookController::class, 'paystack']);
            Route::post('/manual', [PaymentWebhookController::class, 'manual']);
        });
});
