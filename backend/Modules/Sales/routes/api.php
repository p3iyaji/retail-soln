<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\Api\PosHeldSaleController;
use Modules\Sales\Http\Controllers\Api\PosReceiptController;
use Modules\Sales\Http\Controllers\Api\PosSaleController;

Route::prefix('v1')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/pos/sales/hold', [PosHeldSaleController::class, 'hold']);
        Route::get('/pos/sales/held', [PosHeldSaleController::class, 'index']);
        Route::get('/pos/sales/{sale}', [PosHeldSaleController::class, 'show']);
        Route::post('/pos/sales/{sale}/void', [PosHeldSaleController::class, 'void']);
        Route::post('/pos/sales', [PosSaleController::class, 'store']);
        Route::get('/pos/receipts/{sale}', [PosReceiptController::class, 'show']);
        Route::post('/pos/receipts/{sale}/print', [PosReceiptController::class, 'print']);
    });
});
