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
// Polled every 3s for as long as the dashboard is open — not while a job
// runs, but continuously, which spends the interactive budget even faster.
// Read straight from /proc, so it is cheap enough to be worth its own
// allowance rather than a slower refresh.
Route::get('/server/metrics/live', [DashboardController::class, 'live'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:dashboard', 'throttle:progress']);
Route::get('/server/metrics/history', [DashboardController::class, 'history'])->middleware('permission:dashboard');
Route::get('/server/processes', [DashboardController::class, 'processes'])->middleware('permission:dashboard');
// Stopping a process is the one write here — see ProcessKiller for the guards.
Route::delete('/server/processes/{pid}', [DashboardController::class, 'killProcess'])
    ->whereNumber('pid')
    ->middleware('permission:dashboard,manage');
