<?php

use App\Http\Controllers\API\Server\DashboardController;
use Illuminate\Support\Facades\Route;

// Server Dashboard (server panel). Read-only, gated by `dashboard` (view).
// App Dashboard (per-application issues). Gated by `app_dashboard` (view).
Route::get('/applications/{application}/issues', [DashboardController::class, 'issues'])
    ->middleware('permission:app_dashboard');
// Each concern is its own endpoint. Metrics are read live from /proc (+ df);
// history comes from the 5-min `server:sample-metrics` collector.
Route::get('/server/facts', [DashboardController::class, 'facts'])->middleware('permission:dashboard');
Route::get('/server/metrics/live', [DashboardController::class, 'live'])->middleware('permission:dashboard');
Route::get('/server/metrics/history', [DashboardController::class, 'history'])->middleware('permission:dashboard');
Route::get('/server/processes', [DashboardController::class, 'processes'])->middleware('permission:dashboard');
// Stopping a process is the one write here — see ProcessKiller for the guards.
Route::delete('/server/processes/{pid}', [DashboardController::class, 'killProcess'])
    ->whereNumber('pid')
    ->middleware('permission:dashboard,manage');
