<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchasing\Http\Controllers\Api\GoodsReceiptController;
use Modules\Purchasing\Http\Controllers\Api\PurchaseOrderController;
use Modules\Purchasing\Http\Controllers\Api\PurchaseReturnController;
use Modules\Purchasing\Http\Controllers\Api\SupplierController;
use Modules\Purchasing\Http\Controllers\Api\SupplierCsvController;
use Modules\Purchasing\Http\Controllers\Api\SupplierInvoiceController;
use Modules\Purchasing\Http\Controllers\Api\SupplierPaymentController;

Route::prefix('v1')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/suppliers/export', [SupplierCsvController::class, 'export']);
        Route::post('/suppliers/import/preview', [SupplierCsvController::class, 'previewImport']);
        Route::post('/suppliers/import', [SupplierCsvController::class, 'import']);
        Route::get('/suppliers/imports/{import}', [SupplierCsvController::class, 'showImport']);

        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
        Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);

        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
        Route::patch('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update']);
        Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update']);
        Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy']);
        Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit']);
        Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
        Route::post('/purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject']);

        Route::post('/goods-receipts', [GoodsReceiptController::class, 'store']);

        Route::get('/purchase-returns', [PurchaseReturnController::class, 'index']);
        Route::post('/purchase-returns', [PurchaseReturnController::class, 'store']);
        Route::get('/purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'show']);

        Route::get('/supplier-invoices', [SupplierInvoiceController::class, 'index']);
        Route::post('/supplier-invoices', [SupplierInvoiceController::class, 'store']);
        Route::get('/supplier-invoices/{supplierInvoice}', [SupplierInvoiceController::class, 'show']);

        Route::get('/supplier-payments', [SupplierPaymentController::class, 'index']);
        Route::post('/supplier-payments', [SupplierPaymentController::class, 'store']);
        Route::get('/supplier-payments/{supplierPayment}', [SupplierPaymentController::class, 'show']);
    });
});
