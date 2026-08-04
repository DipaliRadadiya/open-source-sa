<?php

use App\Http\Controllers\API\Admin\PanelUpdateController;
use Illuminate\Support\Facades\Route;

/*
| Panel updates — administrator only.
|
| Both routes sit under the `auth:sanctum` + `can:access-admin` middleware
| group in routes/api.php, so the gate runs once for the whole group rather
| than per-route. The controller still checks `isAdmin()` itself (and the
| Action's cache lock + DB guard back it up) because belt-and-braces is the
| cheaper mistake to make here: an unauthenticated POST is the worst
| possible thing to land in this table.
*/

Route::get('/panel-updates', [PanelUpdateController::class, 'index']);
Route::post('/panel-updates', [PanelUpdateController::class, 'store']);
