<?php

use Illuminate\Support\Facades\Route;
use Modules\Reporting\Http\Controllers\Api\DashboardController;
use Modules\Reporting\Http\Controllers\Api\InventoryStockReportController;
use Modules\Reporting\Http\Controllers\Api\SalesPerformanceReportController;

Route::prefix('v1')->group(function (): void {
    Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
        Route::get('/reports/dashboard', [DashboardController::class, 'show']);
        Route::get('/reports/sales', [SalesPerformanceReportController::class, 'show']);
        Route::get('/reports/sales/export', [SalesPerformanceReportController::class, 'export']);
        Route::get('/reports/inventory', [InventoryStockReportController::class, 'show']);
        Route::get('/reports/inventory/export', [InventoryStockReportController::class, 'export']);
    });
});
