<?php

use App\Http\Controllers\API\Admin\RoleController;
use Illuminate\Support\Facades\Route;

Route::get('/roles', [RoleController::class, 'index']);
Route::post('/roles', [RoleController::class, 'store'])->middleware('not.central');
Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('not.central');
Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware('not.central');
