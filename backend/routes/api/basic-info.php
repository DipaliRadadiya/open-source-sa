<?php

use App\Http\Controllers\API\BasicInfoController;
use Illuminate\Support\Facades\Route;

Route::get('/basic-info', [BasicInfoController::class, 'index']);
