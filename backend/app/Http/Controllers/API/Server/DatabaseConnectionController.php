<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Database\SaveConnection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Database\SaveConnectionRequest;
use App\Http\Resources\DatabaseConnectionResource;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Http\JsonResponse;

class DatabaseConnectionController extends Controller
{
    /**
     * Per-engine admin connection config (passwords masked).
     */
    public function index(DatabaseManager $manager): JsonResponse
    {
        $connections = array_map(
            fn (string $engine) => DatabaseConnectionResource::make($manager->connection($engine))->resolve(),
            $manager->engineNames(),
        );

        return response()->json(['connections' => $connections]);
    }

    public function update(string $engine, SaveConnectionRequest $request, SaveConnection $action, DatabaseManager $manager): JsonResponse
    {
        abort_unless(in_array($engine, $manager->engineNames(), true), 404);

        $data = $request->validated();
        $test = (bool) ($data['test'] ?? false);
        unset($data['test']);

        $connection = $action->execute($engine, $data);

        $resolved = DatabaseConnectionResource::make($connection)->resolve();
        if ($test) {
            $resolved['reachable'] = $manager->engine($engine)->available();
        }

        return response()->json([$engine => $resolved]);
    }

    public function test(string $engine, DatabaseManager $manager): JsonResponse
    {
        abort_unless(in_array($engine, $manager->engineNames(), true), 404);

        return response()->json(['reachable' => $manager->engine($engine)->available()]);
    }
}
