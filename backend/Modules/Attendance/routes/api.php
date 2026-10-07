<?php

use Illuminate\Support\Facades\Route;
use Modules\Attendance\Http\Controllers\Api\AttendanceController;
use Modules\Attendance\Http\Controllers\Api\ClockLocationController;

Route::prefix('v1')->group(function (): void {
    Route::middleware(['auth:sanctum', 'staff'])->prefix('attendance')->group(function (): void {
        Route::get('/locations', [ClockLocationController::class, 'index']);
        Route::post('/locations', [ClockLocationController::class, 'store']);
        Route::get('/locations/{location}', [ClockLocationController::class, 'show']);
        Route::patch('/locations/{location}', [ClockLocationController::class, 'update']);
        Route::delete('/locations/{location}', [ClockLocationController::class, 'destroy']);
        Route::post('/locations/{location}/assignments', [ClockLocationController::class, 'assign']);

        Route::post('/clock-in', [AttendanceController::class, 'clockIn']);
        Route::post('/clock-out', [AttendanceController::class, 'clockOut']);
        Route::get('/shifts', [AttendanceController::class, 'shifts']);
        Route::post('/shifts/{shift}/void', [AttendanceController::class, 'void']);
        Route::get('/summary', [AttendanceController::class, 'summary']);
    });
});
