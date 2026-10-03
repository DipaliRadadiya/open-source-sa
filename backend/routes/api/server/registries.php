<?php

use App\Http\Controllers\API\Server\RegistryController;
use Illuminate\Support\Facades\Route;

/*
| Registry credentials — the accounts this server can pull private images with.
|
| Filed with the integrations rather than with Docker's own objects, and the URL
| says so: this is an externally-held credential the features consume, like a git
| account or a storage destination. A network and a volume are things that exist on
| this box; a registry login is a thing that exists somewhere else.
|
| Bound by id, not by name: unlike a network or a volume this is a panel-owned row,
| so its identifier is the database's.
|
| Still gated on the server hosting containers. A credential for pulling container
| images is not useful on a box that runs none, and the screens are hidden there
| too — hiding without gating would leave every endpoint answering.
|
| Every mutation is `registry,manage`, its own permission rather than `docker`:
| managing networks and volumes on this box and storing a credential that can pull
| private code onto it are different powers. Reads are `registry` (view), because
| the picker on a container site's form needs the list.
*/
Route::middleware('hosts-containers')->group(function (): void {
    Route::get('/integrations/registries', [RegistryController::class, 'index'])
        ->middleware(['permission:registry', 'throttle:60,1']);

    Route::post('/integrations/registries', [RegistryController::class, 'store'])
        ->middleware(['permission:registry,manage', 'throttle:20,1']);

    Route::get('/integrations/registries/{registry}', [RegistryController::class, 'show'])
        ->middleware(['permission:registry', 'throttle:60,1']);

    Route::patch('/integrations/registries/{registry}', [RegistryController::class, 'update'])
        ->middleware(['permission:registry,manage', 'throttle:20,1']);

    Route::delete('/integrations/registries/{registry}', [RegistryController::class, 'destroy'])
        ->middleware(['permission:registry,manage', 'throttle:20,1']);

    // Throttled harder than the rest, and deliberately: every call makes an
    // outbound request to a host the caller chose, so a generous limit here is a
    // way to occupy the panel's workers against a registry that never answers.
    // The same reasoning as the storage and git test endpoints.
    Route::post('/integrations/registries/{registry}/test', [RegistryController::class, 'test'])
        ->middleware(['permission:registry,manage', 'throttle:10,1']);
});
