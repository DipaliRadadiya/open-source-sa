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

// Every route in this file, in one place. A Docker server manages no database
// engine — an application that wants one brings it as a container — so these
// answer 409 there rather than acting on an engine that is not the panel's to
// manage. Applied as a group so route number thirty cannot forget it.
Route::middleware('manages-databases')->group(function (): void {

    // Databases (server panel). Read gated by `database` (view), mutations by
    // `database` (manage). 3 engines (mysql|mariadb|mongodb) via a DatabaseEngine
    // strategy; the server is the source of truth, the tables mirror it.

    // Engines capability + admin-connection config (static routes before bindings).
    Route::get('/databases/engines', [DatabaseController::class, 'engines'])->middleware('permission:database');
    Route::post('/databases/engines/{engine}', [DatabaseController::class, 'installEngine'])
        ->middleware('permission:database,manage');
    Route::get('/databases/connections', [DatabaseConnectionController::class, 'index'])->middleware('permission:database');
    Route::put('/databases/connections/{engine}', [DatabaseConnectionController::class, 'update'])->middleware('permission:database,manage');
    Route::post('/databases/connections/{engine}/test', [DatabaseConnectionController::class, 'test'])->middleware('permission:database,manage');

    // Brownfield reconcile.
    Route::get('/databases/untracked', [DatabaseController::class, 'untracked'])->middleware('permission:database');
    Route::post('/databases/adopt', [DatabaseController::class, 'adopt'])->middleware('permission:database,manage');

    // P2 monitoring (static routes before the {database} binding).
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
    | `manage`, matching the backup download for the same reason it gives: this
    | hands over an entire database in one request. Listing the exports stays on the
    | read tier — knowing a dump exists is not the same as being handed it.
    */
    Route::get('/databases/exports/{file}', [DatabaseController::class, 'download'])
        ->where('file', '[A-Za-z0-9._-]+')->middleware('permission:database,manage');

    // Deleting a dump destroys the only copy of that data, so it needs `manage`
    // while merely reading the list does not. Keyed by id, not filename: a queued
    // or failed export has no file and would otherwise be undeletable — visible in
    // the list forever with no way to clear it.
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
    | PUT on the sub-resource rather than PATCH on the database: `name`, `engine`
    | and `charset` are DDL and immutable once created, so a general update endpoint
    | would advertise four editable fields and honour one. This replaces the one
    | relationship that can change, idempotently.
    |
    | `database,manage` alone, matching `store` — which has always accepted
    | `application_id` under exactly that permission.
    */
    Route::put('/databases/{database}/application', [DatabaseController::class, 'updateApplication'])
        ->middleware('permission:database,manage');

    // P2 per-database: table listing.
    //
    // `optimize` and `repair` were here until 2026-09-08 and are deliberately gone
    // rather than hidden. Measured against MariaDB 10.11: `REPAIR TABLE` on InnoDB
    // answers "The storage engine for the table doesn't support repair" as a
    // *note* and exits 0, so the endpoint reported success having done nothing on
    // every table this panel has ever managed. `OPTIMIZE TABLE` on InnoDB is a
    // full table rebuild that ran synchronously against a 60-second timeout, so
    // the databases big enough to want it were exactly the ones where the request
    // died while the server kept rebuilding.
    Route::get('/databases/{database}/tables', [DatabaseController::class, 'tables'])->middleware('permission:database');
    /*
    | `manage`, not the `database` read tier, and throttled.
    |
    | An export copies the entire database off the server, so it is the single most
    | data-revealing thing this feature does. Read access should not be enough to
    | take a full copy of every database.
    |
    | Throttled because a dump is expensive and fills disk: without a limit, a
    | held-down button queues one full copy per click. The job is unique per
    | database as well, but the throttle is what stops the requests arriving.
    */
    Route::post('/databases/{database}/export', [DatabaseController::class, 'export'])
        ->middleware(['permission:database,manage', 'throttle:6,1']);

    /*
    | Import a file into a database, replacing its contents (2026-10-14).
    |
    | `manage` throughout: it rewrites the whole database. The chunk route drops
    | the global `throttle:api` for the reason the file manager's does — one
    | large upload is legitimately hundreds of requests — and is bounded by the
    | declared size, the 2 GB limit and the free-space check instead. `start`
    | is the destructive one and is throttled hard.
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
    | Backups of a database on its own (2026-10-16, v7's type=database).
    | Under the database's permission, by decision. A site's database is
    | backed up with the site, so its target is refused (422).
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
    | phpMyAdmin single sign-on signs the caller in as the database's own user,
    | and that user can write — so it is a write action, whatever the button
    | looks like. It sat behind `database` view (DB-02): a read-only role opened
    | phpMyAdmin and ran an INSERT. Manage, the same bar as every other way of
    | changing a database's contents.
    */
    Route::post('/databases/{database}/phpmyadmin-sso', PhpmyadminSsoController::class)
        ->middleware('permission:database,manage');

    // Adminer's one-click sign-in: the same bar as phpMyAdmin's, for the same
    // reason — the session it opens is the database user's, and it can write.
    Route::post('/databases/{database}/adminer-sso', AdminerSsoController::class)
        ->middleware('permission:database,manage');
    Route::middleware('permission:database,manage')->scopeBindings()->group(function () {
        Route::post('/databases/{database}/users', [DatabaseUserController::class, 'store']);
        Route::patch('/databases/{database}/users/{user}', [DatabaseUserController::class, 'update']);
        Route::put('/databases/{database}/users/{user}/password', [DatabaseUserController::class, 'updatePassword']);
        Route::delete('/databases/{database}/users/{user}', [DatabaseUserController::class, 'destroy']);
    });
});
