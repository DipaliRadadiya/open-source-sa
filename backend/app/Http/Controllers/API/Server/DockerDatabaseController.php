<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Docker\CreateDockerDatabase;
use App\Actions\Server\Docker\DeleteDockerDatabase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Docker\StoreDockerDatabaseRequest;
use App\Http\Resources\DockerDatabaseResource;
use App\Models\DockerDatabase;
use App\Services\ActivityLogger;
use App\Services\Server\Docker\DatabaseContainerFailedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Databases running as containers, for the container sites to connect to.
 *
 * Server-level, with the networks and volumes, because that is what they are: a
 * shared object a site points at. Not applications — see the model for why a
 * database cannot be a site in a panel where every site has a domain and a vhost.
 *
 * **The parameter is `$dockerDatabase`, matching `{dockerDatabase}` in the route,
 * and that is not a style choice.** Implicit binding matches on the NAME: called
 * `$database`, Laravel binds nothing and resolves an empty model from the
 * container instead — so the credentials endpoint answered null for every database
 * and the delete route removed an unsaved row while returning 200. No 404, no
 * error, just endpoints operating on nothing.
 *
 * There is no update endpoint, and that is deliberate rather than unfinished.
 * Every value an engine image reads — the password, the user, the database name —
 * applies to an EMPTY data directory and is ignored afterwards, so a form that
 * appeared to change one would change the file and not the engine. Growing a
 * database means a new one; changing its password means the engine's own
 * `ALTER USER`.
 */
class DockerDatabaseController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'databases' => DockerDatabaseResource::collection(
                DockerDatabase::query()->orderBy('name')->get()
            ),
            // The catalog, so the create form offers what this panel can actually
            // render rather than a list the frontend keeps in step by hand.
            'engines' => collect((array) config('server.docker_databases.engines'))
                ->map(fn (array $engine, string $key): array => [
                    'name' => $key,
                    'label' => (string) $engine['label'],
                    'port' => (int) $engine['port'],
                    'versions' => array_keys((array) $engine['versions']),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreDockerDatabaseRequest $request, CreateDockerDatabase $action): JsonResponse
    {
        try {
            $database = $action->execute($request->validated());
        } catch (DatabaseContainerFailedException $e) {
            // The row and the container were both rolled back by the action, so
            // this says so — "it failed" otherwise leaves somebody wondering
            // whether a half-made database is holding a port.
            return response()->json([
                'message' => __('errors/docker.database_start_failed', ['step' => $e->step]),
                'step' => $e->step,
                'reference' => $e->reference,
            ], 422);
        }

        return response()->json(['database' => new DockerDatabaseResource($database)], 201);
    }

    /**
     * The password, from its own endpoint.
     *
     * Not on the listing, for the reason a container site's generated credentials
     * are not on its payload: a value that rides in every response is a value in
     * every browser cache and proxy log on the way. Gated on `manage` rather than
     * `view` — reading a database password is not a read-only act in any sense
     * that matters — throttled hard, and logged before the response returns,
     * because a response that is never read still handed the value over.
     */
    public function credentials(DockerDatabase $dockerDatabase, ActivityLogger $log): JsonResponse
    {
        $log->log('docker_database.credentials_viewed', $dockerDatabase, [
            'name' => $dockerDatabase->name,
            'engine' => $dockerDatabase->engine,
        ]);

        return response()->json([
            'credentials' => [
                'password' => $dockerDatabase->credential('password'),
                'root_password' => $dockerDatabase->credential('root_password'),
            ],
        ]);
    }

    public function destroy(Request $request, DockerDatabase $dockerDatabase, DeleteDockerDatabase $action): JsonResponse
    {
        $action->execute($dockerDatabase, $request->boolean('remove_data'));

        return response()->json(['message' => __('errors/docker.database_deleted')]);
    }
}
