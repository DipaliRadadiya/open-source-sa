<?php

use App\Http\Controllers\API\ActivityLogController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Static route before the collection so it can never be mistaken for one.
    Route::get('/activity-log/filters', [ActivityLogController::class, 'filters']);
    Route::get('/activity-log', [ActivityLogController::class, 'index']);
});
