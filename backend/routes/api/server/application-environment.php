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
