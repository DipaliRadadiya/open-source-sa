<?php

use App\Http\Controllers\API\Server\AdminerSsoController;
use App\Http\Controllers\API\Server\DatabaseBackupController;
use App\Http\Controllers\API\Server\DatabaseConnectionController;
use App\Http\Controllers\API\Server\DatabaseController;
use App\Http\Controllers\API\Server\DatabaseImportController;
use App\Http\Controllers\API\Server\DatabaseMonitorController;
use App\Http\Controllers\API\Server\DatabaseUserController;
use App\Http\Controllers\API\Server\PhpmyadminSsoController;
use Illuminate\Support\Facades\Route;

// A Docker server manages no database engine (apps bring their own container), so every route
// here answers 409 there. Applied as a group so no route can forget it.
Route::middleware('manages-databases')->group(function (): void {

    // Read gated by `database` (view), mutations by `database` (manage). 3 engines
    // (mysql|mariadb|mongodb) via a DatabaseEngine strategy; the server is the source of truth.

    // Engines capability + admin-connection config (static routes before bindings).
    Route::get('/databases/engines', [DatabaseController::class, 'engines'])->middleware('permission:database');
    Route::post('/databases/engines/{engine}', [DatabaseController::class, 'installEngine'])
        ->middleware('permission:database,manage');
    Route::get('/databases/connections', [DatabaseConnectionController::class, 'index'])->middleware('permission:database');
    Route::put('/databases/connections/{engine}', [DatabaseConnectionController::class, 'update'])->middleware('permission:database,manage');
    Route::post('/databases/connections/{engine}/test', [DatabaseConnectionController::class, 'test'])->middleware('permission:database,manage');
    // MySQL/MariaDB root password: set, change or reset.
    Route::put('/databases/connections/{engine}/root-password', [DatabaseConnectionController::class, 'rootPassword'])
        ->middleware(['permission:database,manage', 'throttle:5,1']);

    // Brownfield reconcile.
    Route::get('/databases/untracked', [DatabaseController::class, 'untracked'])->middleware('permission:database');
    Route::post('/databases/adopt', [DatabaseController::class, 'adopt'])->middleware('permission:database,manage');

    // Monitoring (static routes before the {database} binding).
    Route::get('/databases/processes', [DatabaseMonitorController::class, 'processes'])->middleware('permission:database');
    Route::delete('/databases/processes/{id}', [DatabaseMonitorController::class, 'killProcess'])->middleware('permission:database,manage');
    // Polled while an engine installs.
    Route::get('/databases/status/{engine}', [DatabaseMonitorController::class, 'status'])
        ->withoutMiddleware('throttle:api')
        ->middleware(['permission:database', 'throttle:progress']);
    Route::get('/databases/metrics/history', [DatabaseMonitorController::class, 'history'])->middleware('permission:database');

    // Exports (static; before the {database} binding).
    Route::get('/databases/exports', [DatabaseController::class, 'exports'])->middleware('permission:database');

    /*
    | Export download (strict filename, resolved inside the exports dir).
    |
    | `manage`, like the backup download: it hands over an entire database. Listing stays on read.
    */
    Route::get('/databases/exports/{file}', [DatabaseController::class, 'download'])
        ->where('file', '[A-Za-z0-9._-]+')->middleware('permission:database,manage');

    // Deleting a dump destroys the only copy, so `manage`. Keyed by id, not filename: a queued or
    // failed export has no file and would otherwise be undeletable.
    Route::delete('/databases/exports/{export}', [DatabaseController::class, 'destroyExport'])
        ->where('export', '[0-9]+')->middleware('permission:database,manage');

    // Databases.
    Route::get('/databases', [DatabaseController::class, 'index'])->middleware('permission:database');
    Route::post('/databases', [DatabaseController::class, 'store'])->middleware('permission:database,manage');
    Route::get('/databases/{database}', [DatabaseController::class, 'show'])->middleware('permission:database');
    Route::delete('/databases/{database}', [DatabaseController::class, 'destroy'])->middleware('permission:database,manage');

    /*
    | Which application this database belongs to — attach, move, or detach (null).
    |
    | PUT on the sub-resource, not PATCH on the database: `name`, `engine` and `charset` are DDL
    | and immutable, so a general update endpoint would advertise fields it cannot honour.
    | `database,manage` alone, matching `store`, which accepts `application_id` under it.
    */
    Route::put('/databases/{database}/application', [DatabaseController::class, 'updateApplication'])
        ->middleware('permission:database,manage');

    // Table listing. No `optimize`/`repair` routes on purpose: `REPAIR TABLE` on InnoDB exits 0
    // having done nothing, and `OPTIMIZE TABLE` is a full rebuild that outlives the request timeout.
    Route::get('/databases/{database}/tables', [DatabaseController::class, 'tables'])->middleware('permission:database');
    /*
    | `manage`, not the `database` read tier, and throttled.
    |
    | An export copies the entire database off the server; read access must not be enough.
    | Throttled because a dump is expensive and fills disk (the job is unique per database too).
    */
    Route::post('/databases/{database}/export', [DatabaseController::class, 'export'])
        ->middleware(['permission:database,manage', 'throttle:6,1']);

    /*
    | Import a file into a database, replacing its contents.
    |
    | `manage` throughout: it rewrites the whole database. The chunk route drops the global
    | `throttle:api` (one large upload is hundreds of requests, as in the file manager) and is
    | bounded by the declared size, the 2 GB limit and the free-space check. `start` is the
    | destructive one and is throttled hard.
    */
    Route::get('/databases/{database}/imports', [DatabaseImportController::class, 'index'])
        ->middleware('permission:database');
    Route::post('/databases/{database}/imports', [DatabaseImportController::class, 'store'])
        ->middleware(['permission:database,manage', 'throttle:20,1']);
    Route::put('/databases/{database}/imports/{import}/chunk', [DatabaseImportController::class, 'chunk'])
        ->withoutMiddleware('throttle:api')
        ->middleware(['permission:database,manage', 'throttle:1200,1']);
    Route::post('/databases/{database}/imports/{import}/start', [DatabaseImportController::class, 'start'])
        ->middleware(['permission:database,manage', 'throttle:6,1']);
    Route::delete('/databases/{database}/imports/{import}', [DatabaseImportController::class, 'destroy'])
        ->middleware(['permission:database,manage', 'throttle:20,1']);

    /*
    | Backups of a database on its own (v7's type=database), under the database's permission.
    | A site's database is backed up with the site, so its target is refused (422).
    */
    Route::get('/databases/{database}/backup-target', [DatabaseBackupController::class, 'showTarget'])
        ->middleware('permission:database');
    Route::put('/databases/{database}/backup-target', [DatabaseBackupController::class, 'saveTarget'])
        ->middleware(['permission:database,manage', 'throttle:20,1']);
    Route::delete('/databases/{database}/backup-target', [DatabaseBackupController::class, 'destroyTarget'])
        ->middleware(['permission:database,manage', 'throttle:12,1']);
    Route::get('/databases/{database}/backups', [DatabaseBackupController::class, 'index'])
        ->middleware('permission:database');
    Route::post('/databases/{database}/backups', [DatabaseBackupController::class, 'run'])
        ->middleware(['permission:database,manage', 'throttle:6,1']);
    Route::post('/databases/{database}/backups/{backup}/restore', [DatabaseBackupController::class, 'restore'])
        ->middleware(['permission:database,manage', 'throttle:2,1']);
    Route::get('/databases/{database}/backups/{backup}/download', [DatabaseBackupController::class, 'download'])
        ->middleware(['permission:database,manage', 'throttle:6,1']);
    Route::delete('/databases/{database}/backups/{backup}', [DatabaseBackupController::class, 'destroy'])
        ->middleware(['permission:database,manage', 'throttle:12,1']);

    // Database users (nested — a user belongs to one database).
    Route::middleware('permission:database')->group(function () {
        Route::get('/databases/{database}/users', [DatabaseUserController::class, 'index']);
    });

    /*
    | phpMyAdmin single sign-on signs the caller in as the database's own user, who can write, so
    | it needs `manage`: behind view, a read-only role could run an INSERT.
    */
    Route::post('/databases/{database}/phpmyadmin-sso', PhpmyadminSsoController::class)
        ->middleware('permission:database,manage');

    // Adminer's one-click sign-in: same bar as phpMyAdmin's (the session can write).
    Route::post('/databases/{database}/adminer-sso', AdminerSsoController::class)
        ->middleware('permission:database,manage');
    Route::middleware('permission:database,manage')->scopeBindings()->group(function () {
        Route::post('/databases/{database}/users', [DatabaseUserController::class, 'store']);
        Route::patch('/databases/{database}/users/{user}', [DatabaseUserController::class, 'update']);
        Route::put('/databases/{database}/users/{user}/password', [DatabaseUserController::class, 'updatePassword']);
        Route::delete('/databases/{database}/users/{user}', [DatabaseUserController::class, 'destroy']);
    });
});
