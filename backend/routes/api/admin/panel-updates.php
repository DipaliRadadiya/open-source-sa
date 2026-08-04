<?php

use App\Http\Controllers\API\Admin\PanelUpdateController;
use Illuminate\Support\Facades\Route;

/*
| Panel updates — administrator only.
|
| Sits under the `auth:sanctum` + `can:access-admin` group in routes/api.php.
|
| Read-only for now: it reports installed version, published version and
| whether an update could run here. Throttled because `?refresh=1` makes an
| outbound call to the release host, and that budget is shared per-IP with
| everything else on the box.
*/

Route::get('/panel-update', [PanelUpdateController::class, 'show'])
    ->middleware('throttle:30,1');
