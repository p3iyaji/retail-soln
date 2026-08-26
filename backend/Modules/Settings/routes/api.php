<?php

use Illuminate\Support\Facades\Route;
use Modules\Settings\Http\Controllers\Api\SettingController;

Route::prefix('v1')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/settings', [SettingController::class, 'index']);
        Route::patch('/settings', [SettingController::class, 'upsert']);
    });
});
