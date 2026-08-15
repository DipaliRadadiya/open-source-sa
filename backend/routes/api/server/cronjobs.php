<?php

use App\Http\Controllers\API\Server\CronjobController;
use Illuminate\Support\Facades\Route;

// Cron jobs (server panel). View gated by `cronjob` (view), mutations by
// `cronjob` (manage). Materialised as one file per job under /etc/cron.d.

// Static routes first so they aren't captured by the {cronjob} binding below.
Route::get('/cronjobs/schedule-presets', [CronjobController::class, 'schedulePresets'])->middleware('permission:cronjob');
Route::get('/cronjobs/command-presets', [CronjobController::class, 'commandPresets'])->middleware('permission:cronjob');

// No `throttle:api` here: bootstrap/app.php prepends it to every API route
// already, and naming it again does not re-limit anything — it is the same
// limiter and therefore the same bucket, so the request is counted twice and
// the route silently gets half the allowance. Every other file in this
// directory only ever *removes* it (`withoutMiddleware`), which is the tell.
Route::get('/cronjobs', [CronjobController::class, 'index'])->middleware('permission:cronjob');
Route::post('/cronjobs', [CronjobController::class, 'store'])->middleware('permission:cronjob,manage');
Route::get('/cronjobs/{cronjob}', [CronjobController::class, 'show'])->middleware('permission:cronjob');
Route::put('/cronjobs/{cronjob}', [CronjobController::class, 'update'])->middleware('permission:cronjob,manage');
Route::delete('/cronjobs/{cronjob}', [CronjobController::class, 'destroy'])->middleware('permission:cronjob,manage');
