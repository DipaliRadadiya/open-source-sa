<?php

use App\Http\Controllers\API\Server\DockerResourceController;
use App\Http\Controllers\API\Server\RegistryController;
use Illuminate\Support\Facades\Route;

/*
| Docker networks and volumes. Server-level, because that is what they are:
| shared objects that outlive any one container.
|
| The whole file is gated on the server actually hosting containers — the same
| middleware shape the databases routes use for the opposite reason. On a LEMP
| box these endpoints answer 409 rather than shelling out to a docker binary
| that is not installed and reporting its absence as a server error.
|
| Reads gated by `docker` (view), mutations by `docker` (manage).
*/
Route::middleware('hosts-containers')->group(function (): void {
    Route::get('/docker/networks', [DockerResourceController::class, 'networks'])
        ->middleware(['permission:docker', 'throttle:60,1']);

    Route::post('/docker/networks', [DockerResourceController::class, 'createNetwork'])
        ->middleware(['permission:docker,manage', 'throttle:20,1']);

    // The name is the identifier Docker uses and the one the user sees. A
    // `{name}` segment therefore has to accept dots — `.` is legal in a Docker
    // name — so the default route constraint would silently 404 on `my.net`.
    Route::delete('/docker/networks/{name}', [DockerResourceController::class, 'removeNetwork'])
        ->where('name', '[A-Za-z0-9][A-Za-z0-9_.-]*')
        ->middleware(['permission:docker,manage', 'throttle:20,1']);

    Route::get('/docker/volumes', [DockerResourceController::class, 'volumes'])
        ->middleware(['permission:docker', 'throttle:60,1']);

    Route::post('/docker/volumes', [DockerResourceController::class, 'createVolume'])
        ->middleware(['permission:docker,manage', 'throttle:20,1']);

    Route::delete('/docker/volumes/{name}', [DockerResourceController::class, 'removeVolume'])
        ->where('name', '[A-Za-z0-9][A-Za-z0-9_.-]*')
        ->middleware(['permission:docker,manage', 'throttle:20,1']);

    /*
    | Registry credentials. Bound by id, not by name: unlike a network or a
    | volume, this is a panel-owned row rather than an object Docker knows about,
    | so its identifier is the database's.
    |
    | Every mutation is `docker,manage` — creating one stores a credential that
    | can pull private code onto this box, which is not a `view` action by any
    | reading. Reads are `docker` because the picker on the site form needs them.
    */
    Route::get('/docker/registries', [RegistryController::class, 'index'])
        ->middleware(['permission:docker', 'throttle:60,1']);

    Route::post('/docker/registries', [RegistryController::class, 'store'])
        ->middleware(['permission:docker,manage', 'throttle:20,1']);

    Route::get('/docker/registries/{registry}', [RegistryController::class, 'show'])
        ->middleware(['permission:docker', 'throttle:60,1']);

    Route::patch('/docker/registries/{registry}', [RegistryController::class, 'update'])
        ->middleware(['permission:docker,manage', 'throttle:20,1']);

    Route::delete('/docker/registries/{registry}', [RegistryController::class, 'destroy'])
        ->middleware(['permission:docker,manage', 'throttle:20,1']);

    // Throttled harder than the rest, and deliberately: every call makes an
    // outbound request to a host the caller chose, so a generous limit here is a
    // way to occupy the panel's workers against a registry that never answers.
    // The same reasoning as the storage and git test endpoints.
    Route::post('/docker/registries/{registry}/test', [RegistryController::class, 'test'])
        ->middleware(['permission:docker,manage', 'throttle:10,1']);
});
