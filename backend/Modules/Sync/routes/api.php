<?php

use Illuminate\Support\Facades\Route;
use Modules\Sync\Http\Controllers\Api\OversellExceptionController;
use Modules\Sync\Http\Controllers\Api\SyncPushController;

Route::prefix('v1')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/sync/push', SyncPushController::class);
        Route::post('/sync/oversell-exceptions/{exception}/approve', [OversellExceptionController::class, 'approve']);
    });
});
