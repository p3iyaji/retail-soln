<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\Api\LoginController;
use Modules\Authentication\Http\Controllers\Api\LogoutController;
use Modules\Authentication\Http\Controllers\Api\MeController;
use Modules\Authentication\Http\Controllers\Api\PermissionController;
use Modules\Authentication\Http\Controllers\Api\RoleController;
use Modules\Authentication\Http\Controllers\Api\TwoFactorController;
use Modules\Authentication\Http\Controllers\Api\UserController;

Route::prefix('v1')->group(function (): void {
    Route::get('/authentication/health', function () {
        return response()->json([
            'status' => 'ok',
            'module' => 'authentication',
        ]);
    });

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', LoginController::class)
            ->middleware('throttle:login');

        Route::post('/two-factor-challenge', [TwoFactorController::class, 'challenge'])
            ->middleware('throttle:two-factor');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', LogoutController::class);
            Route::get('/me', MeController::class);

            Route::post('/two-factor/enable', [TwoFactorController::class, 'enable']);
            Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm']);
            Route::delete('/two-factor', [TwoFactorController::class, 'disable']);
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::patch('/users/{user}/roles', [UserController::class, 'assignRole']);
        Route::patch('/users/{user}/stores', [UserController::class, 'assignStores']);

        Route::get('/roles', [RoleController::class, 'index']);
        Route::get('/permissions', [PermissionController::class, 'index']);
    });
});
