<?php

use App\Http\Controllers\API\Server\BackupController;
use App\Http\Controllers\API\Server\BackupDownloadController;
use App\Http\Controllers\API\Server\LocalBackupDownloadController;
use App\Http\Controllers\API\Server\RestoreController;
use Illuminate\Support\Facades\Route;

/*
| Backups: two permissions on purpose.
| `app_backup` configures and triggers backups for one application (whoever manages that site).
| `backup` covers the cross-application history you restore from; restore overwrites live data,
| so it sits behind one set of guardrails.
*/

// The overview: every application and whether it is backed up at all (cross-application, so `backup`).
Route::get('/backup-targets', [BackupController::class, 'indexTargets'])
    ->middleware('permission:backup');

// The restore list: every backup, every application.
Route::get('/backups', [BackupController::class, 'index'])
    ->middleware('permission:backup');

// Polled while a backup runs (archive and upload take minutes).
Route::get('/backups/{backup}', [BackupController::class, 'show'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:backup', 'throttle:progress']);

/*
| Download: a link to the archive itself.
| `manage` (same tier as restore), not the `backup` read tier: one URL hands over every file on
| the site plus a full database dump. Throttled because each call signs a live credential.
*/
Route::get('/backups/{backup}/download', [BackupController::class, 'download'])
    ->middleware(['permission:backup,manage', 'throttle:6,1']);

/*
| Download for SFTP and FTP: prepare, then download.
| These providers have no link, so the download above answers 409 `prepare_required` and the
| archive is copied to the panel first. Same `manage` tier. The status is polled (progress
| limiter); the file is signed, so the link names one copy and dies when that copy is removed.
*/
Route::post('/backups/{backup}/download/prepare', [BackupDownloadController::class, 'store'])
    ->middleware(['permission:backup,manage', 'throttle:6,1']);

Route::get('/backups/{backup}/download/prepare', [BackupDownloadController::class, 'show'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:backup,manage', 'throttle:progress']);

Route::get('/backups/{backup}/download/file', [BackupDownloadController::class, 'file'])
    ->middleware(['permission:backup,manage', 'signed:relative', 'throttle:20,1'])
    ->name('backups.download.file');

// Download for "This server" destinations: handed out by the download endpoint above, so same
// `manage` tier, plus a signature because the key is in the query string.
Route::get('/storage-destinations/{storageDestination}/local-file', [LocalBackupDownloadController::class, 'show'])
    ->middleware(['permission:backup,manage', 'signed:relative', 'throttle:20,1'])
    ->name('storage.local.download');

/*
| Restore, the destructive half.
| `manage` on `backup`, not `app_backup`: overwriting a live site is a different decision from
| configuring its backups. Throttled to 2/min so a double-submit cannot queue a second restore.
*/
Route::post('/backups/{backup}/restore', [RestoreController::class, 'store'])
    ->middleware(['permission:backup,manage', 'throttle:2,1']);

Route::get('/restores', [RestoreController::class, 'index'])
    ->middleware('permission:backup');

/*
| Delete a backup, archive and all.
| `manage` on `backup` (same tier as restore and download), not `app_backup`: this destroys the
| copy that exists to survive somebody's mistake. Throttled so a double-submit cannot race the
| artefact delete.
*/
Route::delete('/backups/{backup}', [BackupController::class, 'destroy'])
    ->middleware(['permission:backup,manage', 'throttle:12,1']);

/*
| Bulk delete: looping the single route would hit its 12/min throttle on the thirteenth row.
| The rate is lower than the single form because one request can carry a hundred backups;
| the ceiling that matters is the one on the request body.
*/
Route::delete('/backups', [BackupController::class, 'destroyMany'])
    ->middleware(['permission:backup,manage', 'throttle:6,1']);

// Retry a failed backup with the same configuration.
Route::post('/backups/{backup}/retry', [BackupController::class, 'retry'])
    ->middleware(['permission:app_backup,manage', 'throttle:6,1']);

/*
| Close out a run stuck in flight after its worker died. Not a cancel: a job that could still
| be executing is refused, because freeing the guard would let a second backup start alongside it.
| `app_backup,manage` (same tier as running one): it unblocks backups, it destroys nothing.
*/
Route::post('/backups/{backup}/clear', [BackupController::class, 'clear'])
    ->middleware(['permission:app_backup,manage', 'throttle:6,1']);

// Polled every couple of seconds. A per-route throttle stacks with the global one rather than
// replacing it, hence `withoutMiddleware('throttle:api')` plus the progress limiter.
Route::get('/restores/{restore}', [RestoreController::class, 'show'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:backup', 'throttle:progress']);

// What the settings form offers: frequencies, types, retention limits.
Route::get('/backup-targets/options', [BackupController::class, 'options'])
    ->middleware('permission:app_backup');

// Per-application settings and manual runs, under `app_backup`. The list above is server-wide
// and needs `backup`, which shows every site.
Route::get('/applications/{application}/backups', [BackupController::class, 'indexForApplication'])
    ->middleware('permission:app_backup');

Route::get('/applications/{application}/backup-target', [BackupController::class, 'showTarget'])
    ->middleware('permission:app_backup');

Route::put('/applications/{application}/backup-target', [BackupController::class, 'saveTarget'])
    ->middleware('permission:app_backup,manage');

/*
| Stop backing this application up.
| `backup,manage`, matching the single delete above: this can take every archive with it.
*/
Route::delete('/applications/{application}/backup-target', [BackupController::class, 'destroyTarget'])
    ->middleware(['permission:backup,manage', 'throttle:12,1']);

// Throttled hard: each call dumps a database and writes a multi-gigabyte archive.
Route::post('/applications/{application}/backups', [BackupController::class, 'run'])
    ->middleware(['permission:app_backup,manage', 'throttle:6,1']);
