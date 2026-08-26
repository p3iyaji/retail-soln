<?php

use Illuminate\Support\Facades\Route;
use Modules\Organization\Http\Controllers\Api\OrganizationController;
use Modules\Organization\Http\Controllers\Api\StoreController;
use Modules\Organization\Http\Controllers\Api\WarehouseController;

Route::prefix('v1')->group(function (): void {
    Route::get('/organization/health', function () {
        return response()->json([
            'status' => 'ok',
            'module' => 'organization',
        ]);
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/organizations/current', [OrganizationController::class, 'show']);
        Route::post('/organizations/current', [OrganizationController::class, 'update']);

        Route::get('/stores', [StoreController::class, 'index']);
        Route::post('/stores', [StoreController::class, 'store']);
        Route::get('/stores/{store}', [StoreController::class, 'show']);
        Route::patch('/stores/{store}', [StoreController::class, 'update']);
        Route::delete('/stores/{store}', [StoreController::class, 'destroy']);

        Route::get('/warehouses', [WarehouseController::class, 'index']);
        Route::post('/warehouses', [WarehouseController::class, 'store']);
        Route::get('/warehouses/{warehouse}', [WarehouseController::class, 'show']);
        Route::patch('/warehouses/{warehouse}', [WarehouseController::class, 'update']);
        Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy']);
    });
});
