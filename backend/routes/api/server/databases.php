<?php

use App\Http\Controllers\API\Server\DatabaseConnectionController;
use App\Http\Controllers\API\Server\DatabaseController;
use App\Http\Controllers\API\Server\DatabaseUserController;
use Illuminate\Support\Facades\Route;

// Databases (server panel). Read gated by `database` (view), mutations by
// `database` (manage). 3 engines (mysql|mariadb|mongodb) via a DatabaseEngine
// strategy; the server is the source of truth, the tables mirror it.

// Engines capability + admin-connection config (static routes before bindings).
Route::get('/databases/engines', [DatabaseController::class, 'engines'])->middleware('permission:database');
Route::get('/databases/connections', [DatabaseConnectionController::class, 'index'])->middleware('permission:database');
Route::put('/databases/connections/{engine}', [DatabaseConnectionController::class, 'update'])->middleware('permission:database,manage');
Route::post('/databases/connections/{engine}/test', [DatabaseConnectionController::class, 'test'])->middleware('permission:database,manage');

// Brownfield reconcile.
Route::get('/databases/untracked', [DatabaseController::class, 'untracked'])->middleware('permission:database');
Route::post('/databases/adopt', [DatabaseController::class, 'adopt'])->middleware('permission:database,manage');

// Databases.
Route::get('/databases', [DatabaseController::class, 'index'])->middleware('permission:database');
Route::post('/databases', [DatabaseController::class, 'store'])->middleware('permission:database,manage');
Route::get('/databases/{database}', [DatabaseController::class, 'show'])->middleware('permission:database');
Route::delete('/databases/{database}', [DatabaseController::class, 'destroy'])->middleware('permission:database,manage');

// Database users (nested — a user belongs to one database).
Route::middleware('permission:database')->group(function () {
    Route::get('/databases/{database}/users', [DatabaseUserController::class, 'index']);
});
Route::middleware('permission:database,manage')->scopeBindings()->group(function () {
    Route::post('/databases/{database}/users', [DatabaseUserController::class, 'store']);
    Route::put('/databases/{database}/users/{user}/password', [DatabaseUserController::class, 'updatePassword']);
    Route::delete('/databases/{database}/users/{user}', [DatabaseUserController::class, 'destroy']);
});
