<?php

use App\Http\Controllers\API\Admin\CentralConnectionController;
use Illuminate\Support\Facades\Route;

/*
| Central-panel connection — administrator only.
|
| Sits under the `auth:sanctum` + `can:access-admin` group in routes/api.php.
|
| `store` is throttled hard: it mints a key with full access to the server, and
| there is no legitimate reason to do that more than a handful of times.
*/

Route::get('/central', [CentralConnectionController::class, 'show']);

Route::post('/central', [CentralConnectionController::class, 'store'])
    ->middleware('throttle:5,1');

Route::delete('/central', [CentralConnectionController::class, 'destroy']);
