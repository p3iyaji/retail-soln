<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\Api\InventoryController;
use Modules\Inventory\Http\Controllers\Api\StockMutationController;
use Modules\Inventory\Http\Controllers\Api\StockTransferController;

Route::prefix('v1')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/inventory/balances', [InventoryController::class, 'balances']);
        Route::get('/inventory/movements', [InventoryController::class, 'movements']);
        Route::get('/inventory/alerts/low-stock', [InventoryController::class, 'lowStockAlerts']);

        Route::post('/inventory/adjustments', [StockMutationController::class, 'storeAdjustment']);
        Route::post('/inventory/counts', [StockMutationController::class, 'storeCount']);
        Route::get('/inventory/counts', [StockMutationController::class, 'indexCounts']);

        Route::get('/inventory/transfers', [StockTransferController::class, 'index']);
        Route::post('/inventory/transfers', [StockTransferController::class, 'store']);
        Route::post('/inventory/transfers/{transfer}/receive', [StockTransferController::class, 'receive']);
    });
});
