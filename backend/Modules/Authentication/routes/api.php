<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\Api\LoginController;
use Modules\Authentication\Http\Controllers\Api\LogoutController;
use Modules\Authentication\Http\Controllers\Api\MeController;
use Modules\Authentication\Http\Controllers\Api\TwoFactorController;

Route::prefix('v1')->group(function (): void {
    Route::get('/authentication/health', function () {
        return response()->json([
            'status' => 'ok',
            'module' => 'authentication',
        ]);
    });

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', LoginController::class)
            ->middleware('throttle:login');

        Route::post('/two-factor-challenge', [TwoFactorController::class, 'challenge'])
            ->middleware('throttle:two-factor');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', LogoutController::class);
            Route::get('/me', MeController::class);

            Route::post('/two-factor/enable', [TwoFactorController::class, 'enable']);
            Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm']);
            Route::delete('/two-factor', [TwoFactorController::class, 'disable']);
        });
    });
});
