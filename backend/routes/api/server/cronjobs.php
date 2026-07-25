<?php

use App\Http\Controllers\API\Server\CronjobController;
use Illuminate\Support\Facades\Route;

// Cron jobs (server panel). View gated by `cronjob` (view), mutations by
// `cronjob` (manage). Materialised as one file per job under /etc/cron.d.

// Static routes first so they aren't captured by the {cronjob} binding below.
Route::get('/cronjobs/schedule-presets', [CronjobController::class, 'schedulePresets'])->middleware('permission:cronjob');
Route::get('/cronjobs/command-presets', [CronjobController::class, 'commandPresets'])->middleware('permission:cronjob');

Route::get('/cronjobs', [CronjobController::class, 'index'])->middleware('permission:cronjob');
Route::post('/cronjobs', [CronjobController::class, 'store'])->middleware('permission:cronjob,manage');
Route::get('/cronjobs/{cronjob}', [CronjobController::class, 'show'])->middleware('permission:cronjob');
Route::put('/cronjobs/{cronjob}', [CronjobController::class, 'update'])->middleware('permission:cronjob,manage');
Route::delete('/cronjobs/{cronjob}', [CronjobController::class, 'destroy'])->middleware('permission:cronjob,manage');
