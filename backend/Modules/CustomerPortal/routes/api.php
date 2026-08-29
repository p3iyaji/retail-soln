<?php

use Illuminate\Support\Facades\Route;
use Modules\CustomerPortal\Http\Controllers\Api\PortalAccountController;
use Modules\CustomerPortal\Http\Controllers\Api\PortalAuthController;

Route::prefix('v1/portal')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [PortalAuthController::class, 'register'])
            ->middleware('throttle:portal-auth');
        Route::post('/login', [PortalAuthController::class, 'login'])
            ->middleware('throttle:portal-auth');
        Route::post('/verify', [PortalAuthController::class, 'verify'])
            ->middleware('throttle:portal-auth');

        Route::middleware(['auth:sanctum', 'portal'])->group(function (): void {
            Route::post('/logout', [PortalAuthController::class, 'logout']);
            Route::get('/me', [PortalAuthController::class, 'me']);
        });
    });

    Route::middleware(['auth:sanctum', 'portal'])->prefix('account')->group(function (): void {
        Route::patch('/profile', [PortalAccountController::class, 'updateProfile']);
        Route::get('/addresses', [PortalAccountController::class, 'indexAddresses']);
        Route::post('/addresses', [PortalAccountController::class, 'storeAddress']);
        Route::patch('/addresses/{address}', [PortalAccountController::class, 'updateAddress']);
        Route::delete('/addresses/{address}', [PortalAccountController::class, 'destroyAddress']);
    });
});
