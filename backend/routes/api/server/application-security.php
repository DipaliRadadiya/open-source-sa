<?php

use App\Http\Controllers\API\Server\ApplicationSecurityController;
use Illuminate\Support\Facades\Route;

// Basic Authentication: one toggle, one username, one password per
// application — its own screen and its own permission (`app_security`),
// separate from the `application` permission everything else on this
// resource uses.
Route::put('/applications/{application}/security', [ApplicationSecurityController::class, 'update'])
    ->middleware(['permission:app_security,manage', 'throttle:10,1']);
