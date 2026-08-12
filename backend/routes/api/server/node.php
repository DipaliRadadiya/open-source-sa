<?php

use App\Http\Controllers\API\Server\NodeController;
use Illuminate\Support\Facades\Route;

/*
 * Node: versions, the default, and npm — one feature behind one permission,
 * mirroring PHP. It used to be a section of Settings gated by `setting`,
 * which also grants the SSH port and the reboot button.
 */

// Polled while a Node version installs — same progress feed as /php.
Route::get('/node', [NodeController::class, 'index'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:node', 'throttle:progress']);
Route::put('/node/default', [NodeController::class, 'setDefault'])->middleware('permission:node,manage');

Route::post('/node/versions', [NodeController::class, 'store'])->middleware('permission:node,manage');
Route::delete('/node/versions/{version}', [NodeController::class, 'destroy'])->middleware('permission:node,manage');
Route::post('/node/versions/{version}/npm', [NodeController::class, 'updateNpm'])->middleware('permission:node,manage');
