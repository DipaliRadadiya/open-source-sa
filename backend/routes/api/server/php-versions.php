<?php

use App\Http\Controllers\API\Server\PhpVersionController;
use Illuminate\Support\Facades\Route;

// PHP versions and their FPM configuration. Gated by `service`, the same
// permission as the Services screen these belong to.
//
// The ini editor is a raw file edit — see PhpVersionManager for why that is
// deliberate, and for the backup/validate/rollback that surrounds it.

Route::get('/php-versions', [PhpVersionController::class, 'index'])->middleware('permission:service');
Route::get('/php-versions/{version}/ini', [PhpVersionController::class, 'showIni'])->middleware('permission:service');
Route::put('/php-versions/{version}/ini', [PhpVersionController::class, 'updateIni'])->middleware('permission:service,manage');
