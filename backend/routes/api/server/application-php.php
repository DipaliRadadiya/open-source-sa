<?php

use App\Http\Controllers\API\Server\ApplicationPhpController;
use Illuminate\Support\Facades\Route;

/*
| One application's PHP — version, limits, and its own FPM pool.
|
| `app_php`, and only for site types that serve PHP; the shared permission
| middleware answers 404 for the rest.
|
| Writes are throttled hard. Each one writes a pool file, tests the FPM
| configuration and reloads the daemon — and a reload touches every PHP site on
| the server, not just this one.
*/

Route::get('/applications/{application}/php', [ApplicationPhpController::class, 'show'])
    ->middleware('permission:app_php');

Route::put('/applications/{application}/php', [ApplicationPhpController::class, 'update'])
    ->middleware(['permission:app_php,manage', 'throttle:10,1']);

// Repair only, for sites the panel did not provision. There is deliberately
// no DELETE counterpart: going back to the shared pool means running as the
// web server's account again, which is the cross-site `.env` read that pool
// isolation exists to close.
Route::post('/applications/{application}/php/isolate', [ApplicationPhpController::class, 'isolate'])
    ->middleware(['permission:app_php,manage', 'throttle:5,1']);
