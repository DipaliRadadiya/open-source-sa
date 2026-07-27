<?php

use App\Http\Controllers\API\Admin\ImpersonationController;
use App\Http\Controllers\API\Admin\UserController;
use App\Http\Controllers\API\Admin\UserRoleController;
use Illuminate\Support\Facades\Route;

Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'store']);
Route::put('/users/{user}', [UserController::class, 'update']);
Route::delete('/users/{user}', [UserController::class, 'destroy']);
Route::put('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
Route::put('/users/{user}/roles', [UserRoleController::class, 'update']);
Route::post('/users/{user}/impersonate', ImpersonationController::class);
