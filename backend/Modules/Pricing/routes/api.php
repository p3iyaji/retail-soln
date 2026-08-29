<?php

use Illuminate\Support\Facades\Route;
use Modules\Pricing\Http\Controllers\Api\PriceController;
use Modules\Pricing\Http\Controllers\Api\PriceListController;
use Modules\Pricing\Http\Controllers\Api\PricingResolveController;
use Modules\Pricing\Http\Controllers\Api\PromotionController;

Route::prefix('v1')->group(function (): void {
    Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
        Route::post('/pricing/resolve', PricingResolveController::class);

        Route::get('/price-lists', [PriceListController::class, 'index']);
        Route::post('/price-lists', [PriceListController::class, 'store']);
        Route::get('/price-lists/{priceList}', [PriceListController::class, 'show']);
        Route::patch('/price-lists/{priceList}', [PriceListController::class, 'update']);
        Route::put('/price-lists/{priceList}', [PriceListController::class, 'update']);
        Route::delete('/price-lists/{priceList}', [PriceListController::class, 'destroy']);

        Route::get('/prices', [PriceController::class, 'index']);
        Route::post('/prices', [PriceController::class, 'store']);
        Route::patch('/prices/{price}', [PriceController::class, 'update']);
        Route::put('/prices/{price}', [PriceController::class, 'update']);
        Route::delete('/prices/{price}', [PriceController::class, 'destroy']);

        Route::get('/promotions', [PromotionController::class, 'index']);
        Route::post('/promotions', [PromotionController::class, 'store']);
        Route::get('/promotions/{promotion}', [PromotionController::class, 'show']);
        Route::patch('/promotions/{promotion}', [PromotionController::class, 'update']);
        Route::put('/promotions/{promotion}', [PromotionController::class, 'update']);
        Route::delete('/promotions/{promotion}', [PromotionController::class, 'destroy']);
    });
});
