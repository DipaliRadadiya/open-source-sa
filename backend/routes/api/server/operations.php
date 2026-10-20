<?php

use App\Http\Controllers\API\Server\OperationController;
use Illuminate\Support\Facades\Route;

// RC-20: every long job in one list — backups, restores, clones, staging runs,
// deployments, installs, imports, exports, syncs, security and panel updates.
// Polled by the header, so on the progress limiter like the other feeds.
Route::get('/operations', [OperationController::class, 'index'])
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:progress');
