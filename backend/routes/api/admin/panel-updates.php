<?php

use App\Http\Controllers\API\Admin\PanelUpdateController;
use Illuminate\Support\Facades\Route;

/*
| Panel updates — administrator only.
|
| Sits under the `auth:sanctum` + `can:access-admin` group in routes/api.php.
|
| `show` is throttled because ?refresh=1 makes an outbound call to the release
| host on a per-IP budget shared with everything else on the box. `store` is
| throttled hard: it takes the panel down and rebuilds it.
*/

Route::get('/panel-update', [PanelUpdateController::class, 'show'])
    ->middleware('throttle:30,1');

Route::post('/panel-update', [PanelUpdateController::class, 'store'])
    ->middleware('throttle:3,1');

// Polled every few seconds while the progress bar moves, so it is deliberately
// cheap — no release-host call, just the row and its state file. On
// `throttle:progress` and outside the global limiter for the same reason as the
// other progress feeds: an update is the one time the panel is least able to
// afford telling its own admin to slow down.
Route::get('/panel-update/{panelUpdate}', [PanelUpdateController::class, 'status'])
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:progress');
