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

// After the static path above, so "latest" is never read as a run id.
Route::get('/server/sync/{run}', [ServerSyncController::class, 'show'])
    ->middleware('permission:sync');
