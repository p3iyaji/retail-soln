<?php

use Illuminate\Support\Facades\Route;
use Modules\Customers\Http\Controllers\Api\CustomerController;
use Modules\Customers\Http\Controllers\Api\CustomerGroupController;

Route::prefix('v1')->group(function (): void {
    Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
        Route::get('/customer-groups', [CustomerGroupController::class, 'index']);
        Route::post('/customer-groups', [CustomerGroupController::class, 'store']);
        Route::patch('/customer-groups/{customerGroup}', [CustomerGroupController::class, 'update']);
        Route::put('/customer-groups/{customerGroup}', [CustomerGroupController::class, 'update']);
        Route::delete('/customer-groups/{customerGroup}', [CustomerGroupController::class, 'destroy']);

        Route::get('/customers/walk-in', [CustomerController::class, 'walkIn']);
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{customer}/history', [CustomerController::class, 'history']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
        Route::put('/customers/{customer}', [CustomerController::class, 'update']);
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);
    });
});
