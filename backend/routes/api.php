<?php

use App\Http\Controllers\Api\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => 'retail-soln',
        ]);
    });

    Route::prefix('webhooks/payments')->group(function (): void {
        Route::post('/stripe', [PaymentWebhookController::class, 'stripe']);
        Route::post('/paystack', [PaymentWebhookController::class, 'paystack']);
        Route::post('/manual', [PaymentWebhookController::class, 'manual']);
    });
});
