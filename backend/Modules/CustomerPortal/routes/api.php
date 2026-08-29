<?php

use Illuminate\Support\Facades\Route;
use Modules\CustomerPortal\Http\Controllers\Api\PortalAccountController;
use Modules\CustomerPortal\Http\Controllers\Api\PortalAuthController;
use Modules\CustomerPortal\Http\Controllers\Api\PortalCartController;
use Modules\CustomerPortal\Http\Controllers\Api\PortalCatalogueController;
use Modules\CustomerPortal\Http\Controllers\Api\PortalCheckoutController;
use Modules\CustomerPortal\Http\Controllers\Api\PortalOrderController;

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

    Route::middleware('throttle:portal-catalogue')->prefix('catalogue')->group(function (): void {
        Route::get('/categories', [PortalCatalogueController::class, 'categories']);
        Route::get('/products', [PortalCatalogueController::class, 'index']);
        Route::get('/products/{product}', [PortalCatalogueController::class, 'show'])
            ->whereNumber('product');
    });

    Route::middleware('throttle:portal-catalogue')->get('/stores', [PortalCheckoutController::class, 'stores']);

    Route::middleware(['auth:sanctum', 'portal', 'portal.verified', 'throttle:portal-cart'])->group(function (): void {
        Route::get('/cart', [PortalCartController::class, 'show']);
        Route::post('/cart/items', [PortalCartController::class, 'add']);
        Route::patch('/cart/items/{item}', [PortalCartController::class, 'update'])
            ->whereNumber('item');
        Route::delete('/cart/items/{item}', [PortalCartController::class, 'destroy'])
            ->whereNumber('item');

        Route::post('/checkout/preview', [PortalCheckoutController::class, 'preview']);
        Route::post('/checkout/place', [PortalCheckoutController::class, 'place']);
        Route::post('/orders/{order}/confirm-payment', [PortalCheckoutController::class, 'confirmManual'])
            ->whereNumber('order');

        Route::get('/orders', [PortalOrderController::class, 'index']);
        Route::get('/orders/{order}', [PortalOrderController::class, 'show'])
            ->whereNumber('order');
        Route::get('/orders/{order}/invoice', [PortalOrderController::class, 'invoice'])
            ->whereNumber('order');
    });
});
