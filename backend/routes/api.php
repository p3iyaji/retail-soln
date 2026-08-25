<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => 'retail-soln',
        ]);
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', function (Request $request) {
            return response()->json([
                'data' => $request->user(),
            ]);
        });
    });
});
