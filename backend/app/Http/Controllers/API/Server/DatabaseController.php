<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Database\AdoptDatabases;
use App\Actions\Server\Database\CreateDatabase;
use App\Actions\Server\Database\DeleteDatabase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Database\AdoptDatabasesRequest;
use App\Http\Requests\Server\Database\StoreDatabaseRequest;
use App\Http\Resources\DatabaseResource;
use App\Models\Database;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DatabaseController extends Controller
{
    /**
     * Supported engines + their live availability (capability list).
     */
    public function engines(DatabaseManager $manager): JsonResponse
    {
        return response()->json(['engines' => $manager->capabilities()]);
    }

    public function index(): JsonResponse
    {
        $databases = Database::query()->withCount('users')->latest()->get();

        return response()->json(['databases' => DatabaseResource::collection($databases)->resolve()]);
    }

    public function store(StoreDatabaseRequest $request, CreateDatabase $action): JsonResponse
    {
        $database = $action->execute($request->validated());

        return response()->json([
            'database' => DatabaseResource::make($database->load('users'))->resolve(),
        ], 201);
    }

    public function show(Database $database): JsonResponse
    {
        return response()->json([
            'database' => DatabaseResource::make($database->load('users'))->resolve(),
        ]);
    }

    public function destroy(Database $database, DeleteDatabase $action): JsonResponse
    {
        $action->execute($database);

        return response()->json(null, 204);
    }

    /**
     * Server databases not yet tracked by the panel (brownfield discovery).
     */
    public function untracked(Request $request, DatabaseManager $manager): JsonResponse
    {
        $engineName = (string) $request->query('engine');
        abort_unless(in_array($engineName, $manager->engineNames(), true), 404);

        $onServer = $manager->engine($engineName)->listDatabases();
        $tracked = Database::query()->where('engine', $engineName)->pluck('name')->all();

        return response()->json([
            'untracked' => array_values(array_diff($onServer, $tracked)),
        ]);
    }

    public function adopt(AdoptDatabasesRequest $request, AdoptDatabases $action): JsonResponse
    {
        $databases = $action->execute($request->validated()['engine'], $request->validated()['names']);

        return response()->json([
            'databases' => DatabaseResource::collection($databases)->resolve(),
        ], 201);
    }
}
