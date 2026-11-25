<?php

use App\Http\Controllers\API\Server\PhpController;
use Illuminate\Support\Facades\Route;

/*
 * PHP: versions, the default, extensions, and each version's ini, behind one permission.
 *
 * Starting and stopping the FPM daemon stays on Services (`service`), next to nginx and redis.
 *
 * The whole file is gated on the server serving PHP sites, not per route.
 * `hosts('php')` is not "is PHP installed": a Docker box has PHP (the panel is Laravel) but
 * serves no PHP sites, so managing versions and ini files there would manage nothing.
 */
Route::middleware('hosts-php')->group(function (): void {

    // Polled while a version installs (PhpOverview reads RuntimeProgress), so it sits outside
    // the global limiter like the other progress feeds.
    Route::get('/php', [PhpController::class, 'index'])
        ->withoutMiddleware('throttle:api')
        ->middleware(['permission:php', 'throttle:progress']);
    Route::put('/php/default', [PhpController::class, 'setDefault'])->middleware('permission:php,manage');

    Route::post('/php/versions', [PhpController::class, 'store'])->middleware('permission:php,manage');
    Route::delete('/php/versions/{version}', [PhpController::class, 'destroy'])->middleware('permission:php,manage');

    // The ini editor is a raw file edit; see PhpVersionManager for the backup/validate/rollback.
    Route::get('/php/versions/{version}/ini', [PhpController::class, 'showIni'])->middleware('permission:php');
    Route::put('/php/versions/{version}/ini', [PhpController::class, 'updateIni'])->middleware('permission:php,manage');
    // The common settings as fields; only their lines change.
    Route::get('/php/versions/{version}/settings', [PhpController::class, 'showIniSettings'])->middleware('permission:php');
    Route::put('/php/versions/{version}/settings', [PhpController::class, 'updateIniSettings'])->middleware('permission:php,manage');

    Route::get('/php/versions/{version}/extensions', [PhpController::class, 'extensions'])->middleware('permission:php');
    Route::put('/php/versions/{version}/extensions/{extension}', [PhpController::class, 'updateExtension'])->middleware('permission:php,manage');

    // ionCube: its own card; it has no apt package, see IonCubeLoader.
    Route::get('/php/versions/{version}/ioncube', [PhpController::class, 'ionCube'])->middleware('permission:php');
    Route::post('/php/versions/{version}/ioncube', [PhpController::class, 'installIonCube'])->middleware('permission:php,manage');
    Route::delete('/php/versions/{version}/ioncube', [PhpController::class, 'removeIonCube'])->middleware('permission:php,manage');
});
