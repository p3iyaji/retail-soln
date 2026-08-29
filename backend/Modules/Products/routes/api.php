<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\Api\CatalogController;
use Modules\Products\Http\Controllers\Api\ProductCatalogToolsController;
use Modules\Products\Http\Controllers\Api\ProductController;
use Modules\Products\Http\Controllers\Api\ProductVariantController;

Route::prefix('v1')->group(function (): void {
    Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
        Route::get('/categories', [CatalogController::class, 'categories']);
        Route::post('/categories', [CatalogController::class, 'storeCategory']);
        Route::get('/brands', [CatalogController::class, 'brands']);
        Route::post('/brands', [CatalogController::class, 'storeBrand']);
        Route::get('/units', [CatalogController::class, 'units']);
        Route::post('/units', [CatalogController::class, 'storeUnit']);

        Route::get('/products/search', [ProductCatalogToolsController::class, 'search']);
        Route::get('/products/export', [ProductCatalogToolsController::class, 'export']);
        Route::post('/products/import/preview', [ProductCatalogToolsController::class, 'previewImport']);
        Route::post('/products/import', [ProductCatalogToolsController::class, 'import']);
        Route::get('/products/imports/{import}', [ProductCatalogToolsController::class, 'showImport']);

        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::patch('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);

        Route::get('/products/{product}/variants', [ProductVariantController::class, 'index']);
        Route::post('/products/{product}/variants', [ProductVariantController::class, 'store']);
        Route::get('/products/{product}/variants/{variant}', [ProductVariantController::class, 'show']);
        Route::patch('/products/{product}/variants/{variant}', [ProductVariantController::class, 'update']);
        Route::delete('/products/{product}/variants/{variant}', [ProductVariantController::class, 'destroy']);
    });
});
