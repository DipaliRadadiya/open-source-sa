<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Backup\DeleteBackup;
use App\Actions\Server\Backup\DeleteBackups;
use App\Actions\Server\Backup\DeleteBackupTarget;
use App\Actions\Server\Backup\SaveBackupTarget;
use App\Enums\BackupStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Backup\BulkDeleteBackupsRequest;
use App\Http\Requests\Server\Backup\IndexBackupsRequest;
use App\Http\Requests\Server\Backup\IndexBackupTargetsRequest;
use App\Http\Requests\Server\Backup\SaveBackupTargetRequest;
use App\Http\Resources\ApplicationBackupResource;
use App\Http\Resources\BackupResource;
use App\Http\Resources\BackupTargetResource;
use App\Jobs\RunBackup;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Services\ActivityLogger;
use App\Services\Server\Backups\StaleBackupReaper;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Support\ListSort;
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
     * Paged, but the counts are not. This used to say "not paginated, because a
     * '5 of 7 protected' built from page one would be wrong" — the observation
     * was right and the conclusion has been narrowed rather than dropped: the
     * counts are now taken with their own aggregate queries over every
     * application, so they describe the server whatever page you are on and
     * whatever you have filtered to. A header that changed when you turned the
     * page would be answering a question nobody asked.
     */
    public function indexTargets(IndexBackupTargetsRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search', ''));
        $filter = (array) $request->validated('filter', []);

        $applications = Application::query()
            ->with(['backupTarget.storageDestination', 'latestBackup'])
            // The filter this screen exists for: which sites are unprotected.
            // array_key_exists rather than `??`, so `filter[protected]=0` is
            // read as a request for the unprotected ones and not as absent.
            ->when(array_key_exists('protected', $filter) && $filter['protected'] !== null,
                fn ($query) => $filter['protected']
                    ? $query->whereHas('backupTarget')
                    : $query->whereDoesntHave('backupTarget'))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';

                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('domain', 'like', $like));
            });

        $applications = ListSort::apply($applications, $request->validated('sort'), IndexBackupTargetsRequest::SORTS, 'asc')
            ->paginate($request->validated('per_page', IndexBackupTargetsRequest::PER_PAGE));

        // Counted, not fetched. `whereHas` on a fresh query is one aggregate
        // each; loading every application to call ->filter() on it would undo
        // the paging this method just did.
        $total = Application::query()->count();
        $protected = Application::query()->whereHas('backupTarget')->count();

        return response()->json([
            'backup_targets' => ApplicationBackupResource::collection($applications->items())->resolve(),
            'meta' => [
                'total' => $total,
                'protected' => $protected,
                'unprotected' => $total - $protected,
                'current_page' => $applications->currentPage(),
                'per_page' => $applications->perPage(),
                // How many rows the current search and filter match, which is
                // not `total` — `total` is how many sites exist. Two different
                // numbers that both deserve a name.
                'matched' => $applications->total(),
                'last_page' => $applications->lastPage(),
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
     * Delete several backups at once.
     *
     * 200 with a per-backup outcome rather than 204, because a batch can
     * half-work and the caller has to be able to say which half. Same
     * `succeeded`/`failed` shape the file manager's bulk operations use, so a
     * client reads one contract for both.
     */
    public function destroyMany(BulkDeleteBackupsRequest $request, DeleteBackups $action): JsonResponse
    {
        // Ordered oldest first so a batch that is cut short by anything has
        // removed the backups least likely to be wanted.
        $backups = Backup::query()
            ->whereIn('id', $request->validated('ids'))
            ->with('target.storageDestination', 'application')
            ->oldest('id')
            ->get();

        $result = $action->execute($backups);

        return response()->json([
            'deleted' => $result['failed'] === [],
            ...$result,
        ]);
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
    public function run(Application $application, StaleBackupReaper $reaper): JsonResponse
    {
        $target = BackupTarget::where('application_id', $application->id)->first();

        if ($target === null) {
            throw ValidationException::withMessages([
                'application' => [__('backup.errors.not_configured')],
            ]);
        }

        // Reaps abandoned rows before answering. Without this a run stranded by
        // a killed worker blocks the site forever: it is in flight by status
        // and gone in fact, and nothing else ever revisits it.
        if ($reaper->hasLiveRun($target)) {
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
    public function retry(Backup $backup, StaleBackupReaper $reaper): JsonResponse
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

        if ($reaper->hasLiveRun($target)) {
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
     * Close out a run that is stuck in flight.
     *
     * The automatic reaping only happens when somebody next asks for a backup,
     * and the wait until a run is provably dead is over an hour. This is the
     * button for the person looking at a site that says "running" from
     * yesterday and wants it back now.
     *
     * **A run that could still be alive is refused.** There is no way from here
     * to kill a job already executing on a worker, so marking it failed would
     * not stop it — it would leave the row saying one thing and the process
     * doing another, and free the guard for a second backup to start alongside
     * the first. Both would then write to the same disk and the same archive
     * key. Refusing and saying when it clears itself is the honest answer.
     */
    public function clear(Backup $backup, StaleBackupReaper $reaper, ActivityLogger $activity): JsonResponse
    {
        if (! in_array($backup->status->value, StaleBackupReaper::IN_FLIGHT, true)) {
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.clear_not_running')],
            ]);
        }

        if (! $reaper->isStale($backup)) {
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.clear_too_soon', [
                    'minutes' => (int) ceil(StaleBackupReaper::staleAfterSeconds() / 60),
                ])],
            ]);
        }

        $reaper->close($backup);

        $activity->log('backup.cleared', $backup->application, [
            'name' => $backup->application?->name ?? '',
        ]);

        return response()->json([
            'backup' => BackupResource::make($backup->fresh())->resolve(),
        ]);
    }
}
