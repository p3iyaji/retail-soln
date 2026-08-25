<?php

use Illuminate\Support\Facades\Route;

/*
| Authentication module API stubs (Story 1.1).
| Fortify login / token issuance lands in Story 1.2.
*/
Route::prefix('v1')->group(function (): void {
    Route::get('/authentication/health', function () {
        return response()->json([
            'status' => 'ok',
            'module' => 'authentication',
        ]);
    });
});
