<?php

use App\Http\Controllers\API\Server\PhpController;
use Illuminate\Support\Facades\Route;

/*
 * PHP: versions, the default, extensions, and each version's ini — one
 * feature behind one permission.
 *
 * These used to live half on Services (`service`) and half in Settings
 * (`setting`), so managing PHP required both — and `setting` also grants the
 * SSH port and the reboot button. Starting and stopping the FPM daemon stays
 * on Services, where it sits alongside nginx and redis; that is a different
 * job from managing PHP.
 */

// Polled while a version installs — apt takes minutes and holds a lock, and
// this is the screen showing that progress (PhpOverview reads RuntimeProgress).
// Outside the global limiter for the same reason as the other progress feeds:
// a watched job must not spend the budget the rest of the panel needs.
Route::get('/php', [PhpController::class, 'index'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:php', 'throttle:progress']);
Route::put('/php/default', [PhpController::class, 'setDefault'])->middleware('permission:php,manage');

Route::post('/php/versions', [PhpController::class, 'store'])->middleware('permission:php,manage');
Route::delete('/php/versions/{version}', [PhpController::class, 'destroy'])->middleware('permission:php,manage');

// The ini editor is a raw file edit — see PhpVersionManager for why that is
// deliberate, and for the backup/validate/rollback that surrounds it.
Route::get('/php/versions/{version}/ini', [PhpController::class, 'showIni'])->middleware('permission:php');
Route::put('/php/versions/{version}/ini', [PhpController::class, 'updateIni'])->middleware('permission:php,manage');

Route::get('/php/versions/{version}/extensions', [PhpController::class, 'extensions'])->middleware('permission:php');
Route::put('/php/versions/{version}/extensions/{extension}', [PhpController::class, 'updateExtension'])->middleware('permission:php,manage');
