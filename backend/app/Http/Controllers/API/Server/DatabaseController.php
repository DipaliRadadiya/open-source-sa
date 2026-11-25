<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Database\AdoptDatabases;
use App\Actions\Server\Database\AttachDatabaseToApplication;
use App\Actions\Server\Database\CreateDatabase;
use App\Actions\Server\Database\DeleteDatabase;
use App\Enums\ExportStatus;
use App\Enums\InstallStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Database\AdoptDatabasesRequest;
use App\Http\Requests\Server\Database\DatabaseEngineRequest;
use App\Http\Requests\Server\Database\IndexDatabasesRequest;
use App\Http\Requests\Server\Database\StoreDatabaseRequest;
use App\Http\Requests\Server\Database\UpdateDatabaseApplicationRequest;
use App\Http\Resources\DatabaseExportResource;
use App\Http\Resources\DatabaseResource;
use App\Jobs\InstallDatabaseEngine;
use App\Jobs\RunDatabaseExport;
use App\Models\Application;
use App\Models\Database;
use App\Models\DatabaseExport;
use App\Models\DatabaseImport;
use App\Models\Restore;
use App\Services\ActivityLogger;
use App\Services\Runtime\DatabaseInstallProgress;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\DatabaseSizes;
use App\Services\Server\Databases\Installers\EngineInstallerManager;
use App\Support\ListSearch;
use App\Support\ListSort;
use App\Support\ProbeCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseController extends Controller
{
    /**
     * Supported engines + their live availability (capability list).
     *
     * `installable` says whether the panel can install this engine itself (whether its config
     * entry names an installer). An engine can be operable without being installable, and
     * the setup page must not offer a button that cannot work.
     *
     * `system_schemas` is the engine's own databases, which the panel never creates, drops or
     * alters; published so the frontend does not hardcode its own list.
     */
    public function engines(DatabaseManager $manager, EngineInstallerManager $installers): JsonResponse
    {
        $progress = app(InstallTracker::class)->versions('database')->keyBy('version');
        // Detection (a version query and a package question per engine) is cached; install
        // progress below is read fresh.
        $capabilities = ProbeCache::remember('database-engines', fn (): array => $manager->capabilities());
        $installed = array_column($capabilities, 'installed', 'engine');

        $engines = array_map(function (array $engine) use ($manager, $installers, $progress, $installed) {
            $name = (string) $engine['engine'];
            $row = $progress->get($name);
            // Read from the detection above, not asked again per row.
            $unavailable = $installers->unavailableReason($name)
                ?? $installers->conflictReason($name, $installed);

            return $engine + [
                'system_schemas' => $manager->systemSchemas($name),
                'installable' => $installers->canInstall($name) && $unavailable === null,
                // Why not, when not: `{code, reason}`, the shape a blocked site-type card
                // carries. Null whenever `installable` is true.
                'unavailable' => $unavailable,
                // Only `installing` or `failed`: a finished install deletes its row, so
                // "installed" comes from detection above.
                'install_status' => $row?->status?->value,
                'install_reason' => $row?->reason,
                // The model's own message, so every screen words a failure the same way.
                'install_message' => $row?->message(),
                'install_progress' => $row === null ? null : DatabaseInstallProgress::describe($row),
            ];
        }, $capabilities);

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
        // Refused here, not discovered two minutes into apt: the vendor publishes nothing for
        // this Ubuntu release, so the install would write a source list and signing key and then
        // fail. Its own sentence, so the user knows it is the OS, not a missing feature.
        if (($unavailable = $installers->unavailableReason($engine)) !== null) {
            abort(422, $unavailable['reason']);
        }

        if (($conflict = $installers->conflictReason($engine, array_column($manager->capabilities(), 'installed', 'engine'))) !== null) {
            abort(422, $conflict['reason']);
        }

        abort_unless($installers->canInstall($engine), 422, __('errors/database.engine_not_installable'));

        // A second click or tab while an install is queued or running: the job's uniqueness would
        // drop it silently and answer 202 for an install that never happens.
        if ($installs->current('database', $engine)?->status === InstallStatus::Installing) {
            return response()->json([
                'message' => __('errors/database.engine_install_in_progress', [
                    'engine' => (string) config("server.databases.engines.{$engine}.label", $engine),
                ]),
                'reason' => 'install_in_progress',
            ], 409);
        }

        $installed = $installers->installer($engine)->installed();

        // "The package is present" is not "the panel can use it": installing is apt plus
        // provisioning the panel's own account, so the check is "installed *and* answering".
        // Otherwise dispatch, which re-runs provisionPanelAccount() (install() skips apt when
        // the package is there).
        if ($installed && $manager->engine($engine)->available()) {
            return response()->json(['engines' => $manager->capabilities(), 'queued' => false]);
        }

        // The row is written before dispatch (inside the job there would be a blind window).
        // "Was this here before we started?" is recorded now: asked again in the job it would be
        // wrong on a retry, where a part-finished install has already put the package on disk.
        $installs->start('database', $engine, initialStep: 'queued', wasAbsent: ! $installed);

        InstallDatabaseEngine::dispatch($engine, Auth::id());

        return response()->json(['queued' => true], 202);
    }

    public function index(IndexDatabasesRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search', ''));
        $filter = (array) $request->validated('filter', []);

        // The rule validates the shape; `boolean()` reads it (`attached=0` arrives as a string).
        $attached = ($filter['attached'] ?? null) === null ? null : $request->boolean('filter.attached');

        $databases = Database::query()
            ->withCount('users')
            // One query for the page; the resource names the application only when loaded.
            ->with('application:id,name')
            ->when($filter['engine'] ?? null, fn ($query, $engine) => $query->where('engine', $engine))
            ->when(
                ($filter['application_id'] ?? null) !== null,
                fn ($query) => $query->where('application_id', $filter['application_id']),
            )
            // Compared against null, not truthiness: `attached=false` would fall through a
            // `when($attached)`.
            ->when(
                $attached !== null,
                fn ($query) => $attached
                    ? $query->whereNotNull('application_id')
                    : $query->whereNull('application_id'),
            )
            ->when($search !== '', fn ($query) => ListSearch::apply($query, $search, ['name']));

        $databases = ListSort::apply(
            $databases,
            $request->validated('sort'),
            IndexDatabasesRequest::SORTS,
            caseInsensitive: ['name'],
        )->paginate($request->validated('per_page', IndexDatabasesRequest::PER_PAGE));

        return response()->json([
            'databases' => DatabaseResource::collection($databases->items())->resolve(),
            'meta' => [
                'current_page' => $databases->currentPage(),
                'per_page' => $databases->perPage(),
                'total' => $databases->total(),
                'last_page' => $databases->lastPage(),
            ],
        ]);
    }

    public function store(StoreDatabaseRequest $request, CreateDatabase $action): JsonResponse
    {
        $database = $action->execute($request->validated());

        return response()->json([
            'database' => DatabaseResource::make($database->load('users'))->resolve(),
        ], 201);
    }

    /**
     * One database, with its size re-measured.
     *
     * The list serves the stored value (measuring every schema per request is slow); a single
     * database is worth one query for the exact figure.
     */
    public function show(Database $database, DatabaseSizes $sizes): JsonResponse
    {
        $unmeasured = $sizes->refreshReporting($database);

        $resource = DatabaseResource::make($database->load('users'))->resolve();

        // An engine that is down answers no size; the stored one (0 for a new database) would
        // read as empty.
        if ($unmeasured !== null) {
            $resource['size_bytes'] = null;
            $resource['size_human'] = null;
        }

        return response()->json([
            'database' => [...$resource, 'size_reason' => $unmeasured],
        ]);
    }

    /**
     * Attach this database to an application, move it, or detach it (null).
     *
     * The link is bookkeeping (it decides which site's backups include the database): see
     * `AttachDatabaseToApplication` for what it does and does not change.
     */
    public function updateApplication(
        UpdateDatabaseApplicationRequest $request,
        Database $database,
        AttachDatabaseToApplication $action,
    ): JsonResponse {
        $applicationId = $request->validated('application_id');

        $database = $action->execute(
            $database,
            $applicationId === null ? null : Application::findOrFail($applicationId),
        );

        return response()->json([
            'database' => DatabaseResource::make($database)->resolve(),
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
    public function untracked(DatabaseEngineRequest $request, DatabaseManager $manager): JsonResponse
    {
        $engineName = $request->engine();

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
        $engine = $manager->engine($database->engine);
        $tables = $engine->tables($database->name);

        // A stopped engine answers `[]`, which would read as an empty database. Asked only when
        // the list is empty, so a working engine pays nothing extra.
        if ($tables === [] && ! $engine->available()) {
            return response()->json([
                'message' => __('errors/database.engine_unreachable', [
                    'engine' => (string) config("server.databases.engines.{$database->engine}.label", $database->engine),
                ]),
                'reason' => 'engine_unreachable',
            ], 503);
        }

        return response()->json([
            'tables' => $tables,
        ]);
    }

    /**
     * Export (dump) a database — read-only, non-destructive.
     *
     * `202`: the work is queued. Poll `GET /databases/exports` and drive the UI from `status`.
     * The row is created before dispatch so the export is visible while it waits for a worker.
     */
    public function export(Database $database): JsonResponse
    {
        // Close out anything stranded first: a worker killed outright reaches neither
        // `RunDatabaseExport::failed()` nor the runner, and an in-flight row blocks the next export.
        DatabaseExport::query()
            ->where('database_id', $database->id)
            ->inFlight()
            ->get()
            ->filter(fn (DatabaseExport $export): bool => $export->isStale())
            ->each(fn (DatabaseExport $export) => $export->update([
                'status' => ExportStatus::Failed,
                // The existing code for "the worker stopped" (an abandoned run, noticed later).
                'reason' => 'worker',
                'finished_at' => now(),
            ]));

        // The job is unique per database too; this half tells the user why instead of
        // silently dropping the dispatch.
        $inFlight = DatabaseExport::query()
            ->where('database_id', $database->id)
            ->inFlight()
            ->exists();

        if ($inFlight) {
            throw ValidationException::withMessages([
                'database' => [__('errors/database.export_already_running')],
            ]);
        }

        // A dump taken during an import would be a half-empty file that looks good.
        $importing = DatabaseImport::query()
            ->where('database_id', $database->id)
            ->inFlight()
            ->get()
            ->contains(fn (DatabaseImport $import): bool => ! $import->isStale());

        if ($importing) {
            throw ValidationException::withMessages([
                'database' => [__('errors/database.export_import_running')],
            ]);
        }

        // Nor while a backup restore is replacing it.
        if (Restore::inProgressForDatabase($database->id)) {
            throw ValidationException::withMessages([
                'database' => [__('backup.errors.restore_database_busy')],
            ]);
        }

        $export = DatabaseExport::create([
            'database_id' => $database->id,
            'database_name' => $database->name,
            'engine' => $database->engine,
            'status' => ExportStatus::Queued,
            'user_id' => Auth::id(),
        ]);

        RunDatabaseExport::dispatch($export->id, $database->id);

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

    /**
     * Every export, newest first.
     *
     * Lets a dump be downloaded without knowing its generated filename. In-flight rows are
     * included so a page does not appear to have done nothing.
     */
    public function exports(): JsonResponse
    {
        // Reaped here as well as in `export()`: this is the list the screen polls, and a job
        // dropped when its queue lock outlived its worker would otherwise leave an endless
        // "Waiting" row.
        DatabaseExport::query()
            ->inFlight()
            ->get()
            ->filter(fn (DatabaseExport $export): bool => $export->isStale())
            ->each(fn (DatabaseExport $export) => $export->update([
                'status' => ExportStatus::Failed,
                // Same code the start path uses for the same condition.
                'reason' => 'worker',
                'finished_at' => now(),
            ]));

        $exports = DatabaseExport::query()->with('user:id,username')->latest('id')->get();

        return response()->json([
            'exports' => DatabaseExportResource::collection($exports)->resolve(),
        ]);
    }

    /**
     * Delete an export — the row and the file it points at.
     *
     * Keyed by id, not filename, so queued and failed rows (which have no file) can be cleared.
     */
    public function destroyExport(DatabaseExport $export, ActivityLogger $log): JsonResponse
    {
        // A queued or running export will still write its file; deleting the row now would
        // orphan it. A stale one (its worker is gone) can be cleared.
        if (in_array($export->status, [ExportStatus::Queued, ExportStatus::Running], true) && ! $export->isStale()) {
            return response()->json([
                'message' => __('errors/database.export_in_progress'),
                'reason' => 'export_in_progress',
            ], 409);
        }

        $log->log('database.export_deleted', null, [
            'name' => $export->database_name,
            'file' => (string) $export->file,
        ]);

        $export->deleteWithFile();

        return response()->json(null, 204);
    }
}
