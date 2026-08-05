<?php

use App\Http\Controllers\API\Server\ApplicationFileController;
use Illuminate\Support\Facades\Route;

/*
| A site's own files. For now, just the one high-value action a full file
| manager buries: resetting ownership and modes after they drift. Browsing,
| editing and upload are separate, deliberately smaller decisions — see the
| file-manager research this repo's memory holds before adding to this file.
|
| Throttled hard: it walks the whole site tree, twice.
*/

Route::post('/applications/{application}/fix-permissions', [ApplicationFileController::class, 'fixPermissions'])
    ->middleware(['permission:app_file,manage', 'throttle:5,1']);
