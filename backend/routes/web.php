<?php

use App\Http\Controllers\ApiReferenceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Live API reference — public dev docs, always renders the current
// API_REFERENCE.md from disk.
Route::get('/docs/api-reference', [ApiReferenceController::class, 'html']);
Route::get('/docs/api-reference.md', [ApiReferenceController::class, 'raw']);
