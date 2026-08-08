<?php

use App\Http\Controllers\API\Server\ApplicationCloneController;
use Illuminate\Support\Facades\Route;

// Site Clone: duplicate an application to a brand-new domain as a fully
// independent site — no ongoing relationship to the source (unlike
// Staging). `app_clone` is in every site type's default feature list, so
// unlike Staging this never 404s on type alone — a source needing a
// database with no CloneStrategy built yet refuses with a clear error
// instead, from inside CloneManager.
// Every clone, newest first. For resuming from a different browser session.
Route::get('/clones', [ApplicationCloneController::class, 'index'])
    ->middleware('permission:app_clone');

Route::post('/applications/{application}/clone', [ApplicationCloneController::class, 'store'])
    ->middleware(['permission:app_clone,manage', 'throttle:5,1']);

// Poll a clone while it runs.
Route::get('/clones/{clone}', [ApplicationCloneController::class, 'show'])
    ->middleware(['permission:app_clone', 'throttle:120,1']);
