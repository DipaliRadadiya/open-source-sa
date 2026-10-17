<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Database\ChangeDatabaseRootPassword;
use App\Actions\Server\Database\SaveConnection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Database\ChangeRootPasswordRequest;
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

    /**
     * Set, change or reset the MySQL/MariaDB root password. Only those two:
     * PostgreSQL and MongoDB have no root account of this kind.
     */
    public function rootPassword(string $engine, ChangeRootPasswordRequest $request, ChangeDatabaseRootPassword $action): JsonResponse
    {
        abort_unless(in_array($engine, ['mysql', 'mariadb'], true), 404);

        $connection = $action->execute($engine, $request->password());

        return response()->json([$engine => DatabaseConnectionResource::make($connection)->resolve()]);
    }

    public function test(string $engine, DatabaseManager $manager): JsonResponse
    {
        abort_unless(in_array($engine, $manager->engineNames(), true), 404);

        return response()->json(['reachable' => $manager->engine($engine)->available()]);
    }
}
