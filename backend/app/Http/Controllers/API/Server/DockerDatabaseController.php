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
 * Server-level, like networks and volumes: a shared object a site points at, not an application
 * (see the model).
 *
 * The parameter must be named `$dockerDatabase` to match `{dockerDatabase}` in the route. Implicit
 * binding matches on the name; otherwise Laravel injects an empty model and the endpoints silently
 * act on nothing.
 *
 * There is no update endpoint on purpose: an engine image reads the password, user and database
 * name only for an EMPTY data directory, so changing them would not reach the engine.
 */
class DockerDatabaseController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'databases' => DockerDatabaseResource::collection(
                DockerDatabase::query()->orderBy('name')->get()
            ),
            // The catalog, so the create form offers only what this panel can render.
            'engines' => collect((array) config('server.docker_databases.engines'))
                ->map(fn (array $engine, string $key): array => [
                    'name' => $key,
                    'label' => (string) $engine['label'],
                    'port' => (int) $engine['port'],
                    // Strings: PHP turns a numeric key like `'18'` into an int, so the same field
                    // would be a number for Postgres and a string for MySQL.
                    'versions' => array_map('strval', array_keys((array) $engine['versions'])),
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
            // The action rolled back both the row and the container.
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
     * Kept off the listing so it does not sit in every browser cache and proxy log. Gated on
     * `manage`, throttled, and logged before the response returns: an unread response still
     * handed the value over.
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
        try {
            $action->execute($dockerDatabase, $request->boolean('remove_data'));
        } catch (DatabaseContainerFailedException $e) {
            // Still listed: the container or its data remains, and the row lets the user retry.
            return response()->json([
                'message' => __('errors/docker.database_delete_failed', ['step' => $e->step]),
                'step' => $e->step,
                'reference' => $e->reference,
            ], 422);
        }

        return response()->json(['message' => __('errors/docker.database_deleted')]);
    }
}
