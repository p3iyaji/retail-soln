<?php

use Illuminate\Support\Facades\Route;
use Modules\Sync\Http\Controllers\Api\OversellExceptionController;
use Modules\Sync\Http\Controllers\Api\SyncPullController;
use Modules\Sync\Http\Controllers\Api\SyncPushController;
use Modules\Sync\Http\Controllers\Api\SyncStatusController;

Route::prefix('v1')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/sync/push', SyncPushController::class);
        Route::get('/sync/pull', SyncPullController::class);
        Route::get('/sync/status', SyncStatusController::class);
        Route::get('/sync/oversell-exceptions', [OversellExceptionController::class, 'index']);
        Route::post('/sync/oversell-exceptions/{exception}/approve', [OversellExceptionController::class, 'approve']);
    });
});