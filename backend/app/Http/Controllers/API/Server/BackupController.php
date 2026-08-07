<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Backup\SaveBackupTarget;
use App\Enums\BackupStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Backup\IndexBackupsRequest;
use App\Http\Requests\Server\Backup\SaveBackupTargetRequest;
use App\Http\Resources\ApplicationBackupResource;
use App\Http\Resources\BackupResource;
use App\Http\Resources\BackupTargetResource;
use App\Jobs\RunBackup;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class BackupController extends Controller
{
    /**
     * Every backup across every application — the restore list.
     *
     * One screen rather than per-application history, because restore
     * overwrites live data and one screen means one set of guardrails.
     */
    public function index(IndexBackupsRequest $request): JsonResponse
    {
        $filter = $request->validated('filter', []);

        $backups = Backup::query()
            ->with('application:id,name,domain')
            ->when($filter['application_id'] ?? null, fn ($query, $id) => $query->where('application_id', $id))
            ->when($filter['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filter['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filter['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            // End of day, not midnight: a user asking for backups "to
            // Tuesday" means Tuesday included, and `<= 2026-03-10 00:00:00`
            // would silently drop everything that ran that day.
            ->when($filter['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->latest('id')
            ->paginate($request->validated('per_page', 20));

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

    /**
     * Every application and its backup configuration — the overview screen.
     *
     * Driven from applications, not from backup targets: listing targets can
     * only ever return the sites that are already protected, and the question
     * this screen exists to answer is which ones are not. `meta` carries the
     * counts so the header does not depend on the caller reducing the list.
     *
     * Not paginated. One server holds a handful of sites, and a "5 of 7
     * protected" built from page one would be wrong.
     */
    public function indexTargets(): JsonResponse
    {
        $applications = Application::query()
            ->with(['backupTarget.storageDestination', 'latestBackup'])
            ->orderBy('name')
            ->get();

        $protected = $applications
            ->filter(fn (Application $application): bool => $application->backupTarget !== null)
            ->count();

        return response()->json([
            'backup_targets' => ApplicationBackupResource::collection($applications)->resolve(),
            'meta' => [
                'total' => $applications->count(),
                'protected' => $protected,
                'unprotected' => $applications->count() - $protected,
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
