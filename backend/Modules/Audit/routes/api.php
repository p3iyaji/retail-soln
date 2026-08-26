<?php

use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\Api\AuditLogController;

Route::prefix('v1')->group(function (): void {
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::patch('/audit-logs/{auditLog}', [AuditLogController::class, 'update']);
        Route::put('/audit-logs/{auditLog}', [AuditLogController::class, 'update']);
        Route::delete('/audit-logs/{auditLog}', [AuditLogController::class, 'destroy']);
    });
});
