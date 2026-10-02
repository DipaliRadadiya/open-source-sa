<?php

use App\Http\Controllers\API\Admin\ImpersonationController;
use App\Http\Controllers\API\Admin\UserController;
use App\Http\Controllers\API\Admin\UserRoleController;
use Illuminate\Support\Facades\Route;

Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'store'])->middleware('not.central');
Route::put('/users/{user}', [UserController::class, 'update'])->middleware('not.central');
Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('not.central');
Route::put('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('not.central');
Route::put('/users/{user}/roles', [UserRoleController::class, 'update'])->middleware('not.central');
Route::post('/users/{user}/impersonate', ImpersonationController::class)->middleware('not.central');
