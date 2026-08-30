<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\Api\CatalogController;
use Modules\Products\Http\Controllers\Api\ProductCatalogToolsController;
use Modules\Products\Http\Controllers\Api\ProductController;
use Modules\Products\Http\Controllers\Api\ProductImageUploadController;
use Modules\Products\Http\Controllers\Api\ProductVariantController;

Route::prefix('v1')->group(function (): void {
    Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
        Route::get('/categories', [CatalogController::class, 'categories']);
        Route::post('/categories', [CatalogController::class, 'storeCategory']);
        Route::get('/categories/{category}', [CatalogController::class, 'showCategory']);
        Route::patch('/categories/{category}', [CatalogController::class, 'updateCategory']);
        Route::put('/categories/{category}', [CatalogController::class, 'updateCategory']);
        Route::delete('/categories/{category}', [CatalogController::class, 'destroyCategory']);

        Route::get('/brands', [CatalogController::class, 'brands']);
        Route::post('/brands', [CatalogController::class, 'storeBrand']);
        Route::get('/brands/{brand}', [CatalogController::class, 'showBrand']);
        Route::patch('/brands/{brand}', [CatalogController::class, 'updateBrand']);
        Route::put('/brands/{brand}', [CatalogController::class, 'updateBrand']);
        Route::delete('/brands/{brand}', [CatalogController::class, 'destroyBrand']);

        Route::get('/units', [CatalogController::class, 'units']);
        Route::post('/units', [CatalogController::class, 'storeUnit']);
        Route::get('/units/{unit}', [CatalogController::class, 'showUnit']);
        Route::patch('/units/{unit}', [CatalogController::class, 'updateUnit']);
        Route::put('/units/{unit}', [CatalogController::class, 'updateUnit']);
        Route::delete('/units/{unit}', [CatalogController::class, 'destroyUnit']);

        Route::get('/products/search', [ProductCatalogToolsController::class, 'search']);
        Route::get('/products/export', [ProductCatalogToolsController::class, 'export']);
        Route::post('/products/import/preview', [ProductCatalogToolsController::class, 'previewImport']);
        Route::post('/products/import', [ProductCatalogToolsController::class, 'import']);
        Route::get('/products/imports/{import}', [ProductCatalogToolsController::class, 'showImport']);
        Route::post('/products/images/upload', [ProductImageUploadController::class, 'store']);

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
