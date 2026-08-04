<?php

use App\Http\Controllers\API\Server\WorkerController;
use Illuminate\Support\Facades\Route;

/*
| An application's background workers. `app_worker`, `manage` to change one.
|
| Only for site types that run their own code — the shared permission
| middleware answers 404 for a WordPress site, so the sidebar hiding it is
| presentation rather than the access rule.
|
| Writes are throttled: each one writes a systemd unit, reloads the daemon and
| restarts processes.
*/

Route::get('/applications/{application}/workers', [WorkerController::class, 'index'])
    ->middleware('permission:app_worker');

Route::post('/applications/{application}/workers', [WorkerController::class, 'store'])
    ->middleware(['permission:app_worker,manage', 'throttle:20,1']);

Route::put('/applications/{application}/workers/{worker}', [WorkerController::class, 'update'])
    ->middleware(['permission:app_worker,manage', 'throttle:20,1']);

Route::delete('/applications/{application}/workers/{worker}', [WorkerController::class, 'destroy'])
    ->middleware(['permission:app_worker,manage', 'throttle:20,1']);

Route::post('/applications/{application}/workers/{worker}/{action}', [WorkerController::class, 'control'])
    ->middleware(['permission:app_worker,manage', 'throttle:30,1'])
    ->whereIn('action', ['start', 'stop', 'restart']);
