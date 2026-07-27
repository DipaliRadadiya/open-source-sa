<?php

use App\Http\Controllers\API\Server\LogController;
use Illuminate\Support\Facades\Route;

// Logs (server panel). Read-only; all gated by `logs` (view). No DB — the
// source registry is resolved and files are read live from disk. The client
// only ever references a source by `key`; paths are resolved server-side.
Route::get('/logs', [LogController::class, 'index'])->middleware('permission:logs');
Route::get('/logs/{key}/download', [LogController::class, 'download'])->middleware('permission:logs');
Route::get('/logs/{key}', [LogController::class, 'show'])->middleware('permission:logs');
