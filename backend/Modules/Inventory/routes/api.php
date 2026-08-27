<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\Api\InventoryController;
use Modules\Inventory\Http\Controllers\Api\StockMutationController;

Route::prefix('v1')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/inventory/balances', [InventoryController::class, 'balances']);
        Route::get('/inventory/movements', [InventoryController::class, 'movements']);

        Route::post('/inventory/adjustments', [StockMutationController::class, 'storeAdjustment']);
        Route::post('/inventory/counts', [StockMutationController::class, 'storeCount']);
        Route::get('/inventory/counts', [StockMutationController::class, 'indexCounts']);
    });
});
