<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Backup\DeleteBackup;
use App\Actions\Server\Backup\DeleteDatabaseBackupTarget;
use App\Actions\Server\Backup\SaveDatabaseBackupTarget;
use App\Enums\BackupType;
use App\Enums\RestoreStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Database\IndexDatabaseBackupsRequest;
use App\Http\Requests\Server\Database\RestoreDatabaseBackupRequest;
use App\Http\Requests\Server\Database\SaveDatabaseBackupTargetRequest;
use App\Http\Resources\BackupTargetResource;
use App\Http\Resources\RestoreResource;
use App\Jobs\RunBackup;
use App\Jobs\RunRestore;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Database;
use App\Models\DatabaseImport;
use App\Models\Restore;
use App\Services\ActivityLogger;
use App\Services\Server\Backups\StaleBackupReaper;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Backups of a database on its own (2026-10-16; v7's `type=database`).
 *
 * Everything under the database's own permission, the decision for this
 * feature: someone who manages a database can schedule, run, download and
 * restore its backups without the server-wide Backups permission. The run,
 * the archive, retention and the restore are the same pipeline a site uses.
 */
class DatabaseBackupController extends Controller
{
    public function showTarget(Database $database): JsonResponse
    {
        $target = BackupTarget::with(['storageDestination', 'latestKeptBackup'])->where('database_id', $database->id)->first();

        return response()->json([
            'backup_target' => $target === null ? null : BackupTargetResource::make($target)->resolve(),
            // A site's database is backed up with the site; the screen says so
            // instead of offering a schedule the API would refuse.
            'backed_up_with' => $database->application_id === null ? null : [
                'application_id' => $database->application_id,
                'application_name' => $database->application?->name,
            ],
        ]);
    }

    public function saveTarget(SaveDatabaseBackupTargetRequest $request, Database $database, SaveDatabaseBackupTarget $action): JsonResponse
    {
        return response()->json([
            'backup_target' => BackupTargetResource::make($action->execute($database, $request->validated()))->resolve(),
        ]);
    }

    public function destroyTarget(Database $database, DeleteDatabaseBackupTarget $action): JsonResponse
    {
        $action->execute($database, request()->boolean('delete_backups'));

        return response()->json(null, 204);
    }

    public function index(IndexDatabaseBackupsRequest $request, Database $database, BackupController $backups): JsonResponse
    {
        return $backups->listBackups($request, ['database_id' => $database->id]);
    }

    /** Run now. 202 with the target, as for a site — the row appears when a worker picks it up. */
    public function run(Database $database, StaleBackupReaper $reaper): JsonResponse
    {
        $target = BackupTarget::where('database_id', $database->id)->first();

        if ($target === null) {
            throw ValidationException::withMessages(['database' => [__('backup.errors.not_configured')]]);
        }

        if (Restore::inProgressForDatabase($database->id) || DatabaseImport::query()->where('database_id', $database->id)->open()->exists()) {
            throw ValidationException::withMessages(['database' => [__('backup.errors.restore_database_busy')]]);
        }

        if ($reaper->hasLiveRun($target)) {
            throw ValidationException::withMessages(['database' => [__('backup.errors.already_running')]]);
        }

        RunBackup::dispatch($target->id, request()->user()?->id);

        return response()->json([
            'backup_target' => BackupTargetResource::make($target->fresh(['storageDestination']))->resolve(),
        ], 202);
    }

    /**
     * Restore one of its own backups, or an archived backup of the same
     * engine, into this database. Checked in the request; queued here.
     */
    public function restore(RestoreDatabaseBackupRequest $request, Database $database, Backup $backup): JsonResponse
    {
        $restore = Restore::create([
            'backup_id' => $backup->id,
            'application_id' => null,
            // The database from the URL — for an archived backup that is the
            // one the user chose, and the only one it can be restored into.
            'database_id' => $database->id,
            'user_id' => $request->user()?->id,
            'type' => BackupType::Database->value,
            'safety_copy' => $request->safetyCopy(),
            'status' => RestoreStatus::Pending,
            'reference' => (string) Str::uuid(),
        ]);

        RunRestore::dispatch($restore->id, 0, $database->id);

        return response()->json(['restore' => RestoreResource::make($restore)->resolve()], 202);
    }

    public function download(Database $database, Backup $backup, BackupController $backups, DestinationDisk $disks, StorageDriverFactory $drivers, ActivityLogger $activity): JsonResponse
    {
        abort_unless($backup->database_id === $database->id, 404);

        return $backups->download($backup, $disks, $drivers, $activity);
    }

    public function destroy(Database $database, Backup $backup, DeleteBackup $action): JsonResponse
    {
        abort_unless($backup->database_id === $database->id, 404);

        $action->execute($backup);

        return response()->json(null, 204);
    }
}
