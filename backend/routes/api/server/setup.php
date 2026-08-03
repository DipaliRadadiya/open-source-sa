<?php

use App\Http\Controllers\API\Server\SetupController;
use Illuminate\Support\Facades\Route;

/*
| The setup page — and the same list as the panel's Services page. One read;
| each component carries the endpoint that installs it, so there is no second
| install route to drift from the originals.
|
| Gated on `setting` (view): there is no `server` permission — the catalog is
| server-wide configuration, which is what `setting` already covers. It is a read;
| the install endpoints it points at keep their own `manage` requirements.
*/

Route::get('/setup', [SetupController::class, 'show'])->middleware('permission:setting');
