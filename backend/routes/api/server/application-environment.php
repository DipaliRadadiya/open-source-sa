<?php

use App\Http\Controllers\API\Server\ApplicationEnvironmentController;
use Illuminate\Support\Facades\Route;

/*
| An application's environment file.
|
| `app_environment`, and only for site types that actually keep one — a
| WordPress site's configuration lives in wp-config.php, and presenting that as
| ".env" would be lying about the file. The controller answers 403 for those
| rather than relying on the sidebar to have hidden the link.
|
| Writes are throttled: each one shells out to read, copy, write and often
| restart a service.
*/

Route::get('/applications/{application}/environment', [ApplicationEnvironmentController::class, 'show'])
    ->middleware('permission:app_environment');

Route::put('/applications/{application}/environment', [ApplicationEnvironmentController::class, 'update'])
    ->middleware(['permission:app_environment,manage', 'throttle:20,1']);

Route::post('/applications/{application}/environment/restore', [ApplicationEnvironmentController::class, 'restore'])
    ->middleware(['permission:app_environment,manage', 'throttle:10,1']);

/*
| Who changed this file, and when.
|
| Gated on `app_environment` view rather than `activity_log`: everyone who
| reaches this route can already read the file's actual secret values, and
| learning who last edited them reveals strictly less than what is already on
| their screen. A separate grant would only mean someone reading a password
| while being told the edit history is none of their business.
*/
Route::get('/applications/{application}/environment/history', [ApplicationEnvironmentController::class, 'history'])
    ->middleware('permission:app_environment');

/*
| What one change did, variable by variable — old value and new value.
|
| `manage`, a level above the history list it expands. The values come off the
| backup files rather than the activity log, so this endpoint shows a manage
| user only what they could already get by restoring a backup and reading it —
| it saves them the round trip. A viewer cannot restore, so previously rotated
| secrets are not otherwise reachable for them, and this would be a genuine
| widening rather than a convenience.
*/
Route::get('/applications/{application}/environment/history/{log}/diff', [ApplicationEnvironmentController::class, 'diff'])
    ->middleware(['permission:app_environment,manage', 'throttle:60,1']);
