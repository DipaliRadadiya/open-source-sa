<?php

use App\Http\Controllers\API\BrandingController;
use Illuminate\Support\Facades\Route;

Route::get('/branding', [BrandingController::class, 'index']);
