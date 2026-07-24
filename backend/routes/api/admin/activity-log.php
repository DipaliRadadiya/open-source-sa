<?php

use App\Http\Controllers\API\Admin\ActivityLogController;
use Illuminate\Support\Facades\Route;

Route::get('/activity-log/filters', [ActivityLogController::class, 'filters']);
Route::get('/activity-log', [ActivityLogController::class, 'index']);
