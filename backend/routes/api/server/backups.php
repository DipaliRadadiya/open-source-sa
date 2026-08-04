<?php

use App\Http\Controllers\API\Server\BackupController;
use App\Http\Controllers\API\Server\RestoreController;
use Illuminate\Support\Facades\Route;

/*
| Backups — two entry points, two permissions, on purpose.
|
| `app_backup` covers configuring and triggering backups for one application:
| it belongs to whoever manages that site.
|
| `backup` covers the cross-application history you restore from. Restore
| overwrites live data, so that list is one screen with one set of guardrails
| rather than a copy inside every application.
*/

// The restore list: every backup, every application.
Route::get('/backups', [BackupController::class, 'index'])
    ->middleware('permission:backup');

Route::get('/backups/{backup}', [BackupController::class, 'show'])
    ->middleware('permission:backup');

/*
| Restore — the destructive half.
|
| `manage` on `backup`, not `app_backup`: overwriting a live site is a
| different decision from configuring its backups, and someone trusted to set
| a schedule is not automatically trusted to replace the site with last
| Tuesday. Throttled to 2/min — there is no honest reason to press this
| repeatedly, and a double-submit would queue a second restore behind the
| first.
*/
Route::post('/backups/{backup}/restore', [RestoreController::class, 'store'])
    ->middleware(['permission:backup,manage', 'throttle:2,1']);

Route::get('/restores', [RestoreController::class, 'index'])
    ->middleware('permission:backup');

// Polled every couple of seconds while the bar moves.
Route::get('/restores/{restore}', [RestoreController::class, 'show'])
    ->middleware(['permission:backup', 'throttle:120,1']);

// Per-application settings and manual runs.
Route::get('/applications/{application}/backup-target', [BackupController::class, 'showTarget'])
    ->middleware('permission:app_backup');

Route::put('/applications/{application}/backup-target', [BackupController::class, 'saveTarget'])
    ->middleware('permission:app_backup,manage');

// Throttled hard: each call dumps a database and writes a multi-gigabyte
// archive. This is not a button to lean on.
Route::post('/applications/{application}/backups', [BackupController::class, 'run'])
    ->middleware(['permission:app_backup,manage', 'throttle:6,1']);
