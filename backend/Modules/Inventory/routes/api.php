<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\Api\InventoryController;

Route::prefix('v1')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/inventory/balances', [InventoryController::class, 'balances']);
        Route::get('/inventory/movements', [InventoryController::class, 'movements']);
    });
});
