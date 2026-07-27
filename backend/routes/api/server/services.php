<?php

use App\Http\Controllers\API\Server\ServiceController;
use Illuminate\Support\Facades\Route;

// Services (server panel). View gated by `service` (view), actions by
// `service` (manage). No DB — live systemd state via systemctl.
Route::get('/services', [ServiceController::class, 'index'])->middleware('permission:service');
Route::put('/services/{service}', [ServiceController::class, 'update'])->middleware('permission:service,manage');
