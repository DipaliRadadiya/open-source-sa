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

/*
| The overview: every application and whether it is backed up at all.
|
| `backup`, not `app_backup` — this is the cross-application view, same as the
| restore list below. Per-site configuration stays under `app_backup`.
*/
Route::get('/backup-targets', [BackupController::class, 'indexTargets'])
    ->middleware('permission:backup');

// The restore list: every backup, every application.
Route::get('/backups', [BackupController::class, 'index'])
    ->middleware('permission:backup');

Route::get('/backups/{backup}', [BackupController::class, 'show'])
    ->middleware('permission:backup');

/*
| Download — a link to the archive itself.
|
| `manage`, the same tier as restore, not the `backup` read tier: this hands
| over every file on the site plus a full database dump in one URL. Someone
| trusted to see that backups happened is not automatically trusted to walk
| away with what is inside them.
|
| Throttled: each call signs a live credential, and there is no reason to
| press it repeatedly.
*/
Route::get('/backups/{backup}/download', [BackupController::class, 'download'])
    ->middleware(['permission:backup,manage', 'throttle:6,1']);

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
