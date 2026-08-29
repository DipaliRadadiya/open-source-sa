<?php

use App\Http\Controllers\API\Server\ApplicationCloneController;
use Illuminate\Support\Facades\Route;

// Site Clone: duplicate an application to a brand-new domain as a fully
// independent site — no ongoing relationship to the source (unlike Staging).
//
// This used to note that `app_clone` was in every site type's default feature
// list, so unlike Staging it "never 404s on type alone" and a database-backed
// source with no CloneStrategy refused from inside CloneManager instead. That
// refusal happened in the queued job, after the 202 — the panel accepted work
// it already knew it could not do. `app_clone` is now withheld from a type
// that needs a database and has no recipe, so this route 404s on type exactly
// as Staging does, and CloneManager's guard is the backstop rather than the
// first line.
// Every clone, newest first. For resuming from a different browser session.
Route::get('/clones', [ApplicationCloneController::class, 'index'])
    ->middleware('permission:app_clone');

Route::post('/applications/{application}/clone', [ApplicationCloneController::class, 'store'])
    ->middleware(['permission:app_clone,manage', 'throttle:5,1']);

// Poll a clone while it runs — copying files and a database is not quick. Its
// old 120/min was capped by the global limiter it stacked with, so it bought no
// headroom and spent the interactive budget while the user watched.
Route::get('/clones/{clone}', [ApplicationCloneController::class, 'show'])
    ->withoutMiddleware('throttle:api')
    ->middleware(['permission:app_clone', 'throttle:progress']);
