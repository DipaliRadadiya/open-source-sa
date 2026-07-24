<?php

use App\Http\Controllers\API\PermissionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::get('/permissions/check', [PermissionController::class, 'check']);
});
