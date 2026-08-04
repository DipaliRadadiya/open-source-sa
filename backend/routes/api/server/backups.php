<?php

use App\Http\Controllers\API\Server\BackupController;
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

// Per-application settings and manual runs.
Route::get('/applications/{application}/backup-target', [BackupController::class, 'showTarget'])
    ->middleware('permission:app_backup');

Route::put('/applications/{application}/backup-target', [BackupController::class, 'saveTarget'])
    ->middleware('permission:app_backup,manage');

// Throttled hard: each call dumps a database and writes a multi-gigabyte
// archive. This is not a button to lean on.
Route::post('/applications/{application}/backups', [BackupController::class, 'run'])
    ->middleware(['permission:app_backup,manage', 'throttle:6,1']);
