<?php

use App\Http\Controllers\API\Admin\ActivityLogController;
use App\Http\Controllers\API\Admin\ActivityRetentionController;
use Illuminate\Support\Facades\Route;

Route::get('/activity-log/filters', [ActivityLogController::class, 'filters']);
Route::get('/activity-log', [ActivityLogController::class, 'index']);

// How long the log is kept (2026-10-29). Admin-only, like the log itself: the
// choice deletes every user's history.
Route::get('/activity-log/retention', [ActivityRetentionController::class, 'show']);
Route::put('/activity-log/retention', [ActivityRetentionController::class, 'update'])
    ->middleware('throttle:10,1');
