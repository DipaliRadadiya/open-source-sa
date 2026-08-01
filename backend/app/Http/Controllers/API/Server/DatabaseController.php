<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Database\AdoptDatabases;
use App\Actions\Server\Database\CreateDatabase;
use App\Actions\Server\Database\DeleteDatabase;
use App\Enums\ExportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Database\AdoptDatabasesRequest;
use App\Http\Requests\Server\Database\StoreDatabaseRequest;
use App\Http\Resources\DatabaseExportResource;
use App\Http\Resources\DatabaseResource;
use App\Jobs\InstallDatabaseEngine;
use App\Jobs\RunDatabaseExport;
use App\Models\Database;
use App\Models\DatabaseExport;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\Installers\EngineInstallerManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseController extends Controller
{
    /**
     * Supported engines + their live availability (capability list).
     *
     * `installable` says whether the panel can put this engine on the server
     * itself. MongoDB is operable but not installable yet — it needs its own apt
     * repository — and the catalog saying so is what stops the setup page
     * offering a button that cannot work.
     */
    public function engines(DatabaseManager $manager, EngineInstallerManager $installers): JsonResponse
    {
        $progress = app(InstallTracker::class)->versions('database')->keyBy('version');

        $engines = array_map(function (array $engine) use ($installers, $progress) {
            $name = (string) $engine['engine'];
            $row = $progress->get($name);

            return $engine + [
                'installable' => $installers->canInstall($name),
                // Only ever `installing` or `failed`: a finished install deletes
                // its row, so "installed" is answered by detection above and
                // there is no second copy of that fact to go stale.
                'install_status' => $row?->status?->value,
                'install_reason' => $row?->reason,
                // The model's own message, so the engine list and the setup page
                // cannot word the same failure differently.
                'install_message' => $row?->message(),
            ];
        }, $manager->capabilities());

        return response()->json(['engines' => $engines]);
    }

    /**
     * Install an engine. `202` — the work is queued; poll `GET /databases/engines`
     * and drive the UI from `install_status`.
     */
    public function installEngine(
        string $engine,
        DatabaseManager $manager,
        EngineInstallerManager $installers,
        InstallTracker $installs,
    ): JsonResponse {
        abort_unless($installers->canInstall($engine), 422, __('errors/database.engine_not_installable'));

        if ($installers->installer($engine)->installed()) {
            return response()->json(['engines' => $manager->capabilities(), 'queued' => false]);
        }

        // The row is written here, before dispatch — inside the job it would
        // leave a blind window between this response and the worker picking it
        // up, during which the setup page would show nothing happening.
        $installs->start('database', $engine);

        InstallDatabaseEngine::dispatch($engine, Auth::id());

        return response()->json(['queued' => true], 202);
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
     *
     * `202` — the work is queued. Poll `GET /databases/exports` (or the row's
     * own id) and drive the UI from `status`.
     *
     * The row is created here, before dispatch, deliberately: started inside
     * the job instead, there is a window between the 202 and a worker picking
     * it up where the export exists and nothing can see it — which is the
     * blindness this table exists to remove.
     */
    public function export(Database $database): JsonResponse
    {
        $export = DatabaseExport::create([
            'database_id' => $database->id,
            'database_name' => $database->name,
            'engine' => $database->engine,
            'status' => ExportStatus::Queued,
            'user_id' => Auth::id(),
        ]);

        RunDatabaseExport::dispatch($export->id);

        return response()->json([
            'export' => DatabaseExportResource::make($export)->resolve(),
        ], 202);
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
