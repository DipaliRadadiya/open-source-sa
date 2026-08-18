<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Backup\DeleteBackup;
use App\Actions\Server\Backup\DeleteBackupTarget;
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
use App\Services\ActivityLogger;
use App\Services\Server\Backups\Storage\DestinationDisk;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
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

        // Per-status counts, same filters as the main query (no pagination).
        // Replaces four separate per_page=1 requests the frontend was making.
        $baseQuery = Backup::query()
            ->when($filter['application_id'] ?? null, fn ($query, $id) => $query->where('application_id', $id))
            ->when($filter['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filter['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filter['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()));

        return response()->json([
            'backups' => BackupResource::collection($backups)->resolve(),
            'meta' => [
                'current_page' => $backups->currentPage(),
                'per_page' => $backups->perPage(),
                'total' => $backups->total(),
                'last_page' => $backups->lastPage(),
                'counts' => [
                    'total' => (clone $baseQuery)->count(),
                    'pending' => (clone $baseQuery)->where('status', BackupStatus::Pending->value)->count(),
                    'running' => (clone $baseQuery)->where('status', BackupStatus::Running->value)->count(),
                    'verifying' => (clone $baseQuery)->where('status', BackupStatus::Verifying->value)->count(),
                    'completed' => (clone $baseQuery)->where('status', BackupStatus::Verified->value)->count(),
                    'failed' => (clone $baseQuery)->where('status', BackupStatus::Failed->value)->count(),
                ],
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
     * Delete one backup, archive included.
     *
     * 204 rather than the deleted row: there is nothing left to return, and
     * echoing a record that no longer exists invites a client to act on it.
     */
    public function destroy(Backup $backup, DeleteBackup $action): JsonResponse
    {
        $action->execute($backup);

        return response()->json(null, 204);
    }

    /**
     * Stop backing this application up.
     *
     * The archives are the destructive part, not the schedule, so this refuses
     * while any exist unless `delete_backups` says otherwise — and then deletes
     * them through the same path a single delete uses.
     */
    public function destroyTarget(Application $application, DeleteBackupTarget $action): JsonResponse
    {
        $action->execute($application, request()->boolean('delete_backups'));

        return response()->json(null, 204);
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

        RunBackup::dispatch($target->id, request()->user()?->id);

        return response()->json([
            'backup_target' => BackupTargetResource::make($target->fresh(['storageDestination']))->resolve(),
        ], 202);
    }

    /**
     * Hand over a time-limited link to the archive itself.
     *
     * Presigned and returned as JSON — the panel never streams the bytes.
     * Proxying would pin one PHP-FPM worker for the length of a
     * multi-gigabyte transfer, and a handful of concurrent downloads is a
     * dead panel; a 302 would send the browser cross-origin carrying headers
     * the presigned signature does not cover.
     *
     * Deliberately not gated on `verified` the way restore is. Restore
     * overwrites a live site, so an unverified source is dangerous;
     * downloading changes nothing, and a failed run's partial archive is
     * sometimes exactly what someone needs to work out what went wrong. The
     * three guards below already exclude every case where there is nothing
     * to hand over.
     */
    public function download(Backup $backup, DestinationDisk $disks, ActivityLogger $activity): JsonResponse
    {
        $key = $backup->manifest['key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.download_no_artifact')],
            ]);
        }

        $destination = $backup->target?->storageDestination;

        if ($destination === null) {
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.download_no_destination')],
            ]);
        }

        $disk = $disks->for($destination);

        if (! $disk->exists($key)) {
            // The row says the archive exists; the bucket disagrees. Saying
            // so beats handing over a link that 404s in the browser, where
            // it looks like the panel is broken rather than the bucket.
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.download_missing')],
            ]);
        }

        // Short: long enough to start the transfer, not long enough for the
        // link to be worth passing around. S3 authorises at signature check,
        // so a download already in flight is unaffected when it lapses.
        $expiresAt = now()->addMinutes(5);

        // A link to every file on the site plus its database dump. Who asked
        // for it belongs in the audit trail — the URL itself does not, since
        // it carries a working credential for those five minutes.
        $activity->log('backup.downloaded', $backup, [
            'application' => $backup->application?->name,
            'backup_id' => $backup->getKey(),
        ]);

        return response()->json([
            'download' => [
                'url' => $disk->temporaryUrl($key, $expiresAt),
                'expires_at' => $expiresAt->format('d-m-Y H:i:s'),
                'filename' => $this->filename($backup),
                'size_bytes' => $backup->size_bytes,
            ],
        ]);
    }

    /**
     * A name that means something in a downloads folder.
     *
     * The object key is a uuid — fine on the destination, useless once four
     * of them are sitting side by side on someone's laptop.
     */
    private function filename(Backup $backup): string
    {
        $site = $backup->application?->domain ?? 'backup';
        $stamp = $backup->created_at?->format('Y-m-d-His') ?? 'unknown';

        // Dots become hyphens before slugging — Str::slug strips them, which
        // turns shop.example.com into "shopexamplecom" and makes the one
        // part of the name the user recognises unreadable.
        return Str::slug(str_replace('.', '-', $site)).'-'.$stamp.'-'.$backup->type->value.'.tar.gz';
    }

    /** Poll one backup while it runs. */
    public function show(Backup $backup): JsonResponse
    {
        return response()->json([
            'backup' => BackupResource::make($backup)->resolve(),
        ]);
    }

    /**
     * Retry a failed backup with the same configuration.
     *
     * Uses the same target as the original run; dispatches a fresh job.
     * Throttled at the same limit as a manual run.
     */
    public function retry(Backup $backup): JsonResponse
    {
        if ($backup->status !== BackupStatus::Failed) {
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.retry_not_failed')],
            ]);
        }

        $target = $backup->target;

        if ($target === null) {
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.retry_no_target')],
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
            throw ValidationException::withMessages([
                'application' => [__('backup.errors.already_running')],
            ]);
        }

        RunBackup::dispatch($target->id, request()->user()?->id);

        return response()->json([
            'backup_target' => BackupTargetResource::make($target->fresh(['storageDestination']))->resolve(),
        ], 202);
    }
}
