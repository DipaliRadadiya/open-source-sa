<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Backup\SaveBackupTarget;
use App\Enums\BackupStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Backup\SaveBackupTargetRequest;
use App\Http\Resources\BackupResource;
use App\Http\Resources\BackupTargetResource;
use App\Jobs\RunBackup;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BackupController extends Controller
{
    /**
     * Every backup across every application — the restore list.
     *
     * One screen rather than per-application history, because restore
     * overwrites live data and one screen means one set of guardrails.
     */
    public function index(Request $request): JsonResponse
    {
        $backups = Backup::query()
            ->with('application:id,name,domain')
            ->when($request->integer('filter.application_id'), fn ($query, $id) => $query->where('application_id', $id))
            ->when($request->string('filter.status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'backups' => BackupResource::collection($backups)->resolve(),
            'meta' => [
                'current_page' => $backups->currentPage(),
                'per_page' => $backups->perPage(),
                'total' => $backups->total(),
                'last_page' => $backups->lastPage(),
            ],
        ]);
    }

    /** The backup settings for one application, or null when unconfigured. */
    public function showTarget(Application $application): JsonResponse
    {
        $target = BackupTarget::with('storageDestination')
            ->where('application_id', $application->id)
            ->first();

        return response()->json([
            'backup_target' => $target === null ? null : BackupTargetResource::make($target)->resolve(),
        ]);
    }

    public function saveTarget(
        SaveBackupTargetRequest $request,
        Application $application,
        SaveBackupTarget $action,
    ): JsonResponse {
        $target = $action->execute($application, $request->validated());

        return response()->json([
            'backup_target' => BackupTargetResource::make($target)->resolve(),
        ]);
    }

    /**
     * Run a backup now.
     *
     * 202 with the target, not the backup: the run happens on the queue and
     * the row does not exist until a worker picks it up. Returning a `Backup`
     * here would mean inventing one, and the client would poll something the
     * runner has not created.
     */
    public function run(Application $application): JsonResponse
    {
        $target = BackupTarget::where('application_id', $application->id)->first();

        if ($target === null) {
            throw ValidationException::withMessages([
                'application' => [__('backup.errors.not_configured')],
            ]);
        }

        $inFlight = Backup::query()
            ->where('backup_target_id', $target->id)
            ->whereIn('status', [
                BackupStatus::Pending->value,
                BackupStatus::Running->value,
                BackupStatus::Verifying->value,
            ])
            ->exists();

        if ($inFlight) {
            // Two archives of the same site at once would compete for the same
            // disk and the same lock. The job is unique per target as well;
            // this is the half that can tell the user why.
            throw ValidationException::withMessages([
                'application' => [__('backup.errors.already_running')],
            ]);
        }

        RunBackup::dispatch($target->id, request()->user()?->id)->onQueue('backups');

        return response()->json([
            'backup_target' => BackupTargetResource::make($target->fresh(['storageDestination']))->resolve(),
        ], 202);
    }

    /** Poll one backup while it runs. */
    public function show(Backup $backup): JsonResponse
    {
        return response()->json([
            'backup' => BackupResource::make($backup)->resolve(),
        ]);
    }
}
