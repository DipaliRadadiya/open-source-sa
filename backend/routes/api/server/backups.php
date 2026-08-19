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

// Polled while a backup runs — a large site's archive and upload take minutes.
Route::get('/backups/{backup}', [BackupController::class, 'show'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:backup', 'throttle:progress']);

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

/*
| Delete a backup, archive and all.
|
| `manage` on `backup`, the same tier as restore and download rather than the
| per-site `app_backup`: this destroys the copy that exists specifically to
| survive somebody's mistake, and whoever can configure a schedule is not
| automatically trusted to remove what it produced.
|
| Throttled for the same reason restore is — there is no honest reason to press
| this repeatedly, and a double-submit would race the artefact delete.
*/
Route::delete('/backups/{backup}', [BackupController::class, 'destroy'])
    ->middleware(['permission:backup,manage', 'throttle:12,1']);

/*
| Bulk delete. Declared before the single route would not matter — the paths
| differ — but it exists because looping the single one from a browser hits
| that 12/minute throttle on the thirteenth row, which is well inside what
| somebody clearing a month of backups will select.
|
| A lower rate than the single form, not a higher one: each request can carry a
| hundred backups, so six a minute is six hundred deletions. The ceiling that
| matters is the one on the request body.
*/
Route::delete('/backups', [BackupController::class, 'destroyMany'])
    ->middleware(['permission:backup,manage', 'throttle:6,1']);

// Retry a failed backup — re-runs with the same configuration.
Route::post('/backups/{backup}/retry', [BackupController::class, 'retry'])
    ->middleware(['permission:app_backup,manage', 'throttle:6,1']);

/*
| Close out a run stuck in flight after its worker died. Not a cancel: a job
| that could still be executing is refused, because nothing here can stop it
| and freeing the guard would let a second backup start alongside the first.
|
| On `app_backup,manage` — the same tier as running one. This unblocks a site's
| backups rather than destroying anything, so it does not belong with delete.
*/
Route::post('/backups/{backup}/clear', [BackupController::class, 'clear'])
    ->middleware(['permission:app_backup,manage', 'throttle:6,1']);

// Polled every couple of seconds while the bar moves. The 120/min it used to
// declare never applied: a per-route throttle stacks with the global one rather
// than replacing it, so the lower of the two won and this was bounded at — and
// spending — the same budget as the rest of the panel.
Route::get('/restores/{restore}', [RestoreController::class, 'show'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:backup', 'throttle:progress']);

// Per-application settings and manual runs.
Route::get('/applications/{application}/backup-target', [BackupController::class, 'showTarget'])
    ->middleware('permission:app_backup');

Route::put('/applications/{application}/backup-target', [BackupController::class, 'saveTarget'])
    ->middleware('permission:app_backup,manage');

/*
| Stop backing this application up.
|
| `backup,manage` rather than `app_backup,manage`, matching the single delete
| above: removing the schedule is a per-site decision, but this can take every
| archive with it, and that is the same power as deleting them one by one.
*/
Route::delete('/applications/{application}/backup-target', [BackupController::class, 'destroyTarget'])
    ->middleware(['permission:backup,manage', 'throttle:12,1']);

// Throttled hard: each call dumps a database and writes a multi-gigabyte
// archive. This is not a button to lean on.
Route::post('/applications/{application}/backups', [BackupController::class, 'run'])
    ->middleware(['permission:app_backup,manage', 'throttle:6,1']);
