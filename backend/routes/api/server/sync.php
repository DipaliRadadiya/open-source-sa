<?php

use App\Http\Controllers\API\Server\ServerSyncController;
use Illuminate\Support\Facades\Route;

/*
| Reading a migrated server into the panel.
|
| Starting a run is `manage`; watching one is `view`, so a read-only operator
| can follow a migration without being able to begin one.
|
| The start endpoint is throttled hard: a run walks the whole box, and the
| controller already refuses a second one while any run is live.
*/

Route::get('/server/sync/latest', [ServerSyncController::class, 'latest'])
    ->middleware('permission:sync');

Route::post('/server/sync', [ServerSyncController::class, 'store'])
    ->middleware(['permission:sync,manage', 'throttle:10,1']);

Route::get('/server/sync/ignores', [ServerSyncController::class, 'ignores'])
    ->middleware('permission:sync');

Route::post('/server/sync/ignores', [ServerSyncController::class, 'ignore'])
    ->middleware(['permission:sync,manage', 'throttle:60,1']);

Route::delete('/server/sync/ignores/{ignore}', [ServerSyncController::class, 'unignore'])
    ->middleware(['permission:sync,manage', 'throttle:60,1']);

// After the static paths above, so "latest" and "ignores" are never read as
// a run id.
// The line-by-line feed, polled from a cursor for as long as the run takes —
// so it is bounded by `throttle:progress` on its own rather than sharing the
// global budget with whatever else the user is doing while they watch.
Route::get('/server/sync/{run}', [ServerSyncController::class, 'show'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:sync', 'throttle:progress']);
