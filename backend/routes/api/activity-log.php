<?php

use App\Http\Controllers\API\ActivityLogController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/activity-log', [ActivityLogController::class, 'index']);
