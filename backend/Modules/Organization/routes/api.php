<?php

use Illuminate\Support\Facades\Route;

/*
| Organization module API stubs (Story 1.1).
| Domain CRUD lands in Story 1.3+.
*/
Route::prefix('v1')->group(function (): void {
    Route::get('/organization/health', function () {
        return response()->json([
            'status' => 'ok',
            'module' => 'organization',
        ]);
    });
});
