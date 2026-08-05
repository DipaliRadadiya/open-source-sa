<?php

use App\Http\Controllers\API\Server\ApplicationStagingController;
use Illuminate\Support\Facades\Route;

// Staging Area: clone a WordPress site to a second domain, work on it
// safely, push changes back. `app_staging` — WordPress only for now (see
// App\Contracts\StagingStrategy; a site type with no strategy 404s here).
// Deleting a staging site is not a distinct endpoint — it is just another
// application, removed the same way any application is.
Route::get('/applications/{application}/staging', [ApplicationStagingController::class, 'show'])
    ->middleware('permission:app_staging');

Route::post('/applications/{application}/staging', [ApplicationStagingController::class, 'store'])
    ->middleware(['permission:app_staging,manage', 'throttle:5,1']);

Route::post('/applications/{application}/staging/push', [ApplicationStagingController::class, 'push'])
    ->middleware(['permission:app_staging,manage', 'throttle:5,1']);
