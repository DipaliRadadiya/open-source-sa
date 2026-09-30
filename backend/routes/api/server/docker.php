<?php

use App\Http\Controllers\API\Server\DockerDatabaseController;
use App\Http\Controllers\API\Server\DockerResourceController;
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
    | What the box can be asked for, so the CPU and memory fields can state it
    | rather than describe the rule. `docker` (view) rather than `manage`: it is
    | read by the same forms that read the network list, and somebody who may see
    | a container site's settings may see how big the server is.
    |
    | A higher throttle than the mutations because it is page furniture — every
    | visit to a container site's settings screen asks for it.
    */
    Route::get('/docker/limits', [DockerResourceController::class, 'limits'])
        ->middleware(['permission:docker', 'throttle:60,1']);

    /*
    | Containerised database engines — shared objects the container sites connect
    | to, filed here with the networks and volumes because that is what they are.
    | Bound by id, not by name: this is a panel-owned row.
    |
    | Gated on `docker` rather than a permission of its own. A network and a volume
    | are already in this grant, and the volume one is the guard that protects a
    | database's data — splitting them would let somebody delete the volume holding
    | a database while being unable to see the database.
    |
    | No update route. Every value an engine reads applies to an empty data
    | directory and is ignored afterwards, so a form that appeared to change one
    | would change the file and not the engine.
    */
    Route::get('/docker/databases', [DockerDatabaseController::class, 'index'])
        ->middleware(['permission:docker', 'throttle:60,1']);

    Route::post('/docker/databases', [DockerDatabaseController::class, 'store'])
        ->middleware(['permission:docker,manage', 'throttle:10,1']);

    // Throttled hardest of the three. A GET that returns a database password is
    // worth a low ceiling against a token that has leaked, and every call is
    // recorded.
    Route::get('/docker/databases/{dockerDatabase}/credentials', [DockerDatabaseController::class, 'credentials'])
        ->middleware(['permission:docker,manage', 'throttle:6,1']);

    Route::delete('/docker/databases/{dockerDatabase}', [DockerDatabaseController::class, 'destroy'])
        ->middleware(['permission:docker,manage', 'throttle:20,1']);
});
