<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Database\ImportDatabase;
use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Database\BeginDatabaseImportRequest;
use App\Http\Requests\Server\Database\StartDatabaseImportRequest;
use App\Http\Resources\DatabaseImportResource;
use App\Jobs\RunDatabaseImport;
use App\Models\Database;
use App\Models\DatabaseExport;
use App\Models\DatabaseImport;
use App\Models\Restore;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ChunkedUpload;
use App\Services\Server\Databases\Imports\ImportFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Import a file into a database, replacing its contents.
 *
 * Upload (begin → chunk… → start), then a queued run that backs the database
 * up, replaces it, and puts the backup back if the load fails. The client
 * polls `index` for progress, the same way the export list is polled.
 */
class DatabaseImportController extends Controller
{
    public function __construct(private ImportFiles $files) {}

    public function index(Database $database): JsonResponse
    {
        $this->reap($database);

        $imports = DatabaseImport::query()
            ->where('database_id', $database->id)
            ->with('user:id,username')
            ->latest('id')
            ->limit(20)
            ->get();

        return response()->json(['imports' => DatabaseImportResource::collection($imports)->resolve()]);
    }

    public function store(BeginDatabaseImportRequest $request, Database $database): JsonResponse
    {
        $this->reap($database);

        // Refused before the upload: the run would refuse anyway, after the
        // user had sent two gigabytes for nothing.
        if (ImportDatabase::account($database) === null) {
            throw ValidationException::withMessages([
                'database' => [__('errors/database.import_no_account')],
            ]);
        }

        if (DatabaseImport::query()->where('database_id', $database->id)->open()->exists()) {
            throw ValidationException::withMessages([
                'database' => [__('errors/database.import_already_running')],
            ]);
        }

        $import = DatabaseImport::create([
            'database_id' => $database->id,
            'database_name' => $database->name,
            'engine' => $database->engine,
            'original_name' => basename(str_replace('\\', '/', (string) $request->validated('file_name'))),
            'size_bytes' => (int) $request->validated('size_bytes'),
            'status' => ImportStatus::Uploading,
            'user_id' => $request->user()->id,
        ]);

        try {
            $this->files->begin($import);
        } catch (ValidationException $e) {
            $import->delete();

            throw $e;
        }

        return response()->json([
            'import' => DatabaseImportResource::make($import->refresh())->resolve(),
            // What one chunk may be — the same ceiling the file manager's
            // resumable upload reports, since both go through the same PHP.
            'max_chunk' => ChunkedUpload::maxChunkBytes(),
        ], 201);
    }

    /**
     * One chunk, as a raw body. Returns what has arrived so far, which doubles
     * as the resume point after a dropped connection.
     */
    public function chunk(Request $request, Database $database, DatabaseImport $import): JsonResponse
    {
        abort_unless($import->database_id === $database->id, 404);

        if ($import->status !== ImportStatus::Uploading) {
            return response()->json([
                'message' => __('errors/database.import_not_uploading'),
                'reason' => 'import_not_uploading',
            ], 409);
        }

        $stream = $request->getContent(true);
        abort_unless(is_resource($stream), 400);

        try {
            $received = $this->files->append($import, $stream);
        } finally {
            fclose($stream);
        }

        // Touches updated_at as well, which is what keeps a slow upload from
        // being reaped as abandoned.
        $import->update(['received_bytes' => $received]);

        return response()->json(['received' => $received]);
    }

    public function start(StartDatabaseImportRequest $request, Database $database, DatabaseImport $import, ActivityLogger $log): JsonResponse
    {
        abort_unless($import->database_id === $database->id, 404);

        if ($import->status !== ImportStatus::Uploading) {
            return response()->json([
                'message' => __('errors/database.import_not_uploading'),
                'reason' => 'import_not_uploading',
            ], 409);
        }

        if ($this->files->received($import) !== $import->size_bytes) {
            throw ValidationException::withMessages([
                'file' => [__('errors/database.import_incomplete')],
            ]);
        }

        // An export reading the database while it is dropped would hand the
        // user a half-empty dump that looks like a good one.
        if (DatabaseExport::query()->where('database_id', $database->id)->inFlight()->get()->contains(fn ($e) => ! $e->isStale())) {
            throw ValidationException::withMessages([
                'database' => [__('errors/database.import_export_running')],
            ]);
        }

        // A backup restore replacing the same database (2026-10-16).
        if (Restore::inProgressForDatabase($database->id)) {
            throw ValidationException::withMessages([
                'database' => [__('backup.errors.restore_database_busy')],
            ]);
        }

        $import->update(['status' => ImportStatus::Queued, 'received_bytes' => $import->size_bytes]);

        RunDatabaseImport::dispatch($import->id, $database->id);

        $log->log('database.import_started', $database, [
            'name' => $database->name,
            'file' => $import->original_name,
        ]);

        return response()->json(['import' => DatabaseImportResource::make($import->refresh())->resolve()], 202);
    }

    /**
     * Cancel an upload, or clear a finished row. Not while it is queued or
     * running: the worker is about to use the file, or already changing the
     * database.
     */
    public function destroy(Database $database, DatabaseImport $import): Response|JsonResponse
    {
        abort_unless($import->database_id === $database->id, 404);

        if (in_array($import->status->value, DatabaseImport::IN_FLIGHT, true) && ! $import->isStale()) {
            return response()->json([
                'message' => __('errors/database.import_in_progress'),
                'reason' => 'import_in_progress',
            ], 409);
        }

        $this->files->delete($import);
        $import->delete();

        return response()->noContent();
    }

    /**
     * Close out what can no longer finish: an upload nobody has added to for
     * a day, or a run whose worker is gone. Otherwise one stranded row would
     * block every later import of this database.
     */
    private function reap(Database $database): void
    {
        DatabaseImport::query()
            ->where('database_id', $database->id)
            ->open()
            ->get()
            ->filter(fn (DatabaseImport $import): bool => $import->isStale())
            ->each(function (DatabaseImport $import): void {
                $this->files->delete($import);
                $import->update([
                    'status' => ImportStatus::Failed,
                    'reason' => $import->status === ImportStatus::Uploading ? 'abandoned' : 'worker',
                    'finished_at' => now(),
                ]);
            });
    }
}
