<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Database\AdoptDatabases;
use App\Actions\Server\Database\CreateDatabase;
use App\Actions\Server\Database\DeleteDatabase;
use App\Actions\Server\Database\ExportDatabase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Database\AdoptDatabasesRequest;
use App\Http\Requests\Server\Database\StoreDatabaseRequest;
use App\Http\Resources\DatabaseResource;
use App\Models\Database;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    /**
     * Tables/collections in a database (structure peek, no data browsing).
     */
    public function tables(Database $database, DatabaseManager $manager): JsonResponse
    {
        return response()->json([
            'tables' => $manager->engine($database->engine)->tables($database->name),
        ]);
    }

    public function optimize(Database $database, DatabaseManager $manager, ActivityLogger $log): JsonResponse
    {
        $manager->engine($database->engine)->optimize($database->name);
        $log->log('database.optimized', $database, ['name' => $database->name]);

        return response()->json(['database' => DatabaseResource::make($database)->resolve()]);
    }

    public function repair(Database $database, DatabaseManager $manager, ActivityLogger $log): JsonResponse
    {
        $manager->engine($database->engine)->repair($database->name);
        $log->log('database.repaired', $database, ['name' => $database->name]);

        return response()->json(['database' => DatabaseResource::make($database)->resolve()]);
    }

    /**
     * Export (dump) a database — read-only, non-destructive.
     */
    public function export(Database $database, ExportDatabase $action): JsonResponse
    {
        $export = $action->execute($database);
        $export['download_url'] = url('/api/databases/exports/'.$export['file']);

        return response()->json(['export' => $export], 201);
    }

    /**
     * Stream a previously-created export for download. Filename is strictly
     * validated + resolved inside the exports dir (no path traversal).
     */
    public function download(string $file): BinaryFileResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9._-]+$/', $file) === 1, 404);

        $path = rtrim((string) config('server.databases.export_dir'), '/').'/'.basename($file);
        abort_unless(is_file($path), 404);

        return response()->download($path);
    }
}
