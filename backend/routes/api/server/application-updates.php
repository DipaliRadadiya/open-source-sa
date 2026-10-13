<?php

use App\Http\Controllers\API\Server\ApplicationUpdateController;
use Illuminate\Support\Facades\Route;

// One-click update of a site's own software, as v7 offered for Moodle, Mautic,
// Statamic, Craft CMS, Uptime Kuma and phpMyAdmin. Within the installed major
// only. The backup is the user's to take first, from the backups screen.
//
// GET is also the poll while an update runs, so it takes the progress limiter
// rather than the interactive one.
Route::get('/applications/{application}/update', [ApplicationUpdateController::class, 'show'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:application', 'throttle:progress']);

Route::post('/applications/{application}/update', [ApplicationUpdateController::class, 'store'])
    ->middleware(['permission:application,manage', 'throttle:10,1']);
