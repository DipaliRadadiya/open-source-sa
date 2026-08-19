<?php

use App\Http\Controllers\API\Server\DatabaseConnectionController;
use App\Http\Controllers\API\Server\DatabaseController;
use App\Http\Controllers\API\Server\DatabaseMonitorController;
use App\Http\Controllers\API\Server\DatabaseUserController;
use App\Http\Controllers\API\Server\PhpmyadminSsoController;
use Illuminate\Support\Facades\Route;

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

// P2 per-database: table listing + maintenance.
Route::get('/databases/{database}/tables', [DatabaseController::class, 'tables'])->middleware('permission:database');
Route::post('/databases/{database}/optimize', [DatabaseController::class, 'optimize'])->middleware('permission:database,manage');
Route::post('/databases/{database}/repair', [DatabaseController::class, 'repair'])->middleware('permission:database,manage');
/*
| `manage`, not the `database` read tier, and throttled.
|
| An export copies the entire database off the server, so it is the single most
| data-revealing thing this feature does — more so than `optimize` and `repair`
| directly above, which merely rearrange data and have always required `manage`.
| Read access should not be enough to take a full copy of every database.
|
| Throttled because a dump is expensive and fills disk: without a limit, a
| held-down button queues one full copy per click. The job is unique per
| database as well, but the throttle is what stops the requests arriving.
*/
Route::post('/databases/{database}/export', [DatabaseController::class, 'export'])
    ->middleware(['permission:database,manage', 'throttle:6,1']);

// Database users (nested — a user belongs to one database).
Route::middleware('permission:database')->group(function () {
    Route::get('/databases/{database}/users', [DatabaseUserController::class, 'index']);
    Route::post('/databases/{database}/phpmyadmin-sso', PhpmyadminSsoController::class);
});
Route::middleware('permission:database,manage')->scopeBindings()->group(function () {
    Route::post('/databases/{database}/users', [DatabaseUserController::class, 'store']);
    Route::patch('/databases/{database}/users/{user}', [DatabaseUserController::class, 'update']);
    Route::put('/databases/{database}/users/{user}/password', [DatabaseUserController::class, 'updatePassword']);
    Route::delete('/databases/{database}/users/{user}', [DatabaseUserController::class, 'destroy']);
});
