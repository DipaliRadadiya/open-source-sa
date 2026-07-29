<?php

use App\Http\Controllers\API\Server\ServiceController;
use Illuminate\Support\Facades\Route;

// Services (server panel). View gated by `service` (view), actions by
// `service` (manage). No DB — live systemd state via systemctl.
Route::get('/services', [ServiceController::class, 'index'])->middleware('permission:service');
// Validate a service's configuration. Read-only — it never reloads.
Route::post('/services/{service}/config-test', [ServiceController::class, 'configTest'])->middleware('permission:service');
Route::put('/services/{service}', [ServiceController::class, 'update'])->middleware('permission:service,manage');
