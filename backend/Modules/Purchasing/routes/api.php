<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchasing\Http\Controllers\Api\SupplierController;
use Modules\Purchasing\Http\Controllers\Api\SupplierCsvController;

Route::prefix('v1')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/suppliers/export', [SupplierCsvController::class, 'export']);
        Route::post('/suppliers/import/preview', [SupplierCsvController::class, 'previewImport']);
        Route::post('/suppliers/import', [SupplierCsvController::class, 'import']);
        Route::get('/suppliers/imports/{import}', [SupplierCsvController::class, 'showImport']);

        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
        Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);
    });
});
