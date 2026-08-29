<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\Api\InstallmentPlanController;
use Modules\Sales\Http\Controllers\Api\PosCashierSessionController;
use Modules\Sales\Http\Controllers\Api\PosHeldSaleController;
use Modules\Sales\Http\Controllers\Api\PosReceiptController;
use Modules\Sales\Http\Controllers\Api\PosReturnController;
use Modules\Sales\Http\Controllers\Api\PosSaleController;
use Modules\Sales\Http\Controllers\Api\ProformaInvoiceController;

Route::prefix('v1')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/pos/sessions/open', [PosCashierSessionController::class, 'open']);
        Route::get('/pos/sessions/current', [PosCashierSessionController::class, 'current']);
        Route::get('/pos/sessions', [PosCashierSessionController::class, 'index']);
        Route::get('/pos/sessions/{session}', [PosCashierSessionController::class, 'show']);
        Route::post('/pos/sessions/{session}/close', [PosCashierSessionController::class, 'close']);
        Route::post('/pos/sessions/{session}/cash-movements', [PosCashierSessionController::class, 'storeMovement']);
        Route::post('/pos/sales/hold', [PosHeldSaleController::class, 'hold']);
        Route::get('/pos/sales/held', [PosHeldSaleController::class, 'index']);
        Route::get('/pos/sales/{sale}', [PosHeldSaleController::class, 'show']);
        Route::post('/pos/sales/{sale}/void', [PosHeldSaleController::class, 'void']);
        Route::post('/pos/sales', [PosSaleController::class, 'store']);
        Route::get('/pos/sales/{sale}/returnable', [PosReturnController::class, 'showReturnable']);
        Route::post('/pos/returns', [PosReturnController::class, 'store']);
        Route::get('/pos/receipts/{sale}', [PosReceiptController::class, 'show']);
        Route::post('/pos/receipts/{sale}/print', [PosReceiptController::class, 'print']);
        Route::get('/installment-plans', [InstallmentPlanController::class, 'index']);
        Route::get('/installment-plans/{installmentPlan}', [InstallmentPlanController::class, 'show']);
        Route::post('/installment-plans/{installmentPlan}/payments', [InstallmentPlanController::class, 'collectPayment']);
        Route::post('/installment-plans/{installmentPlan}/cancel', [InstallmentPlanController::class, 'cancel']);
        Route::get('/proforma-invoices', [ProformaInvoiceController::class, 'index']);
        Route::post('/proforma-invoices', [ProformaInvoiceController::class, 'store']);
        Route::get('/proforma-invoices/{proformaInvoice}', [ProformaInvoiceController::class, 'show']);
        Route::patch('/proforma-invoices/{proformaInvoice}', [ProformaInvoiceController::class, 'update']);
        Route::post('/proforma-invoices/{proformaInvoice}/issue', [ProformaInvoiceController::class, 'issue']);
        Route::post('/proforma-invoices/{proformaInvoice}/cancel', [ProformaInvoiceController::class, 'cancel']);
        Route::post('/proforma-invoices/{proformaInvoice}/convert', [ProformaInvoiceController::class, 'convert']);
        Route::get('/proforma-invoices/{proformaInvoice}/pdf', [ProformaInvoiceController::class, 'pdf']);
    });
});
