<?php

use Illuminate\Support\Facades\Route;
use Modules\Reporting\Http\Controllers\Api\DashboardController;

Route::prefix('v1')->group(function (): void {
    Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
        Route::get('/reports/dashboard', [DashboardController::class, 'show']);
    });
});
