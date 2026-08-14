<?php

use App\Http\Controllers\API\Admin\ApiErrorLogController;
use Illuminate\Support\Facades\Route;

Route::get('/error-logs', [ApiErrorLogController::class, 'index']);
