<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Backup\DeleteBackup;
use App\Actions\Server\Backup\DeleteBackups;
use App\Actions\Server\Backup\DeleteBackupTarget;
use App\Actions\Server\Backup\SaveBackupTarget;
use App\Contracts\PreparesDownloads;
use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Backup\BulkDeleteBackupsRequest;
use App\Http\Requests\Server\Backup\IndexApplicationBackupsRequest;
use App\Http\Requests\Server\Backup\IndexBackupsRequest;
use App\Http\Requests\Server\Backup\IndexBackupTargetsRequest;
use App\Http\Requests\Server\Backup\SaveBackupTargetRequest;
use App\Http\Resources\ApplicationBackupResource;
use App\Http\Resources\BackupResource;
use App\Http\Resources\BackupTargetResource;
use App\Http\Resources\DatabaseBackupOverviewResource;
use App\Jobs\RunBackup;
use App\Models\Application;
use App\Models\Backup;
use App\Models\BackupTarget;
use App\Models\Database;
use App\Models\Restore;
use App\Services\ActivityLogger;
use App\Services\Server\Backups\StaleBackupReaper;
use App\Services\Server\Backups\Storage\DestinationDisk;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use App\Services\Server\Disk\DiskSpaceCheck;
use App\Services\Server\Docker\VolumeSizes;
use App\Support\BackupCoverage;
use App\Support\ListSearch;
use App\Support\ListSort;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class BackupController extends Controller
{
    /**
     * Every backup across every application — the restore list.
     *
     * One screen, because restore overwrites live data and needs one set of guardrails.
     */
    public function index(IndexBackupsRequest $request): JsonResponse
    {
        return $this->listBackups($request);
    }

    /**
     * One application's backups, same shape as the list above.
     */
    public function indexForApplication(IndexApplicationBackupsRequest $request, Application $application): JsonResponse
    {
        return $this->listBackups($request, ['application_id' => $application->id]);
    }

    /**
     * @param  array{application_id?: int, database_id?: int}  $scope  fixed by the URL, never by the query
     */
    public function listBackups(IndexBackupsRequest $request, array $scope = []): JsonResponse
    {
        $filter = $scope + $request->validated('filter', []);
        $archived = array_key_exists('archived', $filter) && $filter['archived'] !== null ? (bool) $filter['archived'] : null;

        $backups = Backup::query()
            // Eager-loaded to avoid a query per row; the resource's `whenLoaded` shows the field,
            // so the two must move together.
            ->with(['application:id,name,domain', 'database:id,name,engine', 'storageDestination:id,name'])
            ->when($filter['application_id'] ?? null, fn ($query, $id) => $query->where('application_id', $id))
            ->tap(fn ($query) => $this->subjectFilters($query, $filter, $archived))
            ->when($filter['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filter['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filter['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            // End of day, not midnight: "to Tuesday" includes Tuesday.
            ->when($filter['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->latest('id')
            ->paginate($request->validated('per_page', 20));

        // Per-status counts, same filters as the main query (no pagination).
        $baseQuery = Backup::query()
            ->when($filter['application_id'] ?? null, fn ($query, $id) => $query->where('application_id', $id))
            ->tap(fn ($query) => $this->subjectFilters($query, $filter, $archived))
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
     * What the backup settings form offers, so the frontend holds no list of its own.
     *
     * Built from the constants the save validation uses, so offered values are accepted values.
     * Labels and hints come back translated. `time` says which picker a frequency needs:
     * `minute` for hourly, `time` for the rest, null for manual.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'frequencies' => array_map(fn (string $frequency): array => [
                'value' => $frequency,
                'label' => __('backup.frequency.'.$frequency),
                'time' => BackupTarget::timeUsage($frequency),
                'hint' => __('backup.frequency_hint.'.$frequency),
            ], BackupTarget::FREQUENCIES),
            'default_frequency' => 'daily',
            // `family`: this endpoint has no application to scope to, and the families are not
            // interchangeable (`SaveBackupTargetRequest` refuses the mismatch). The caller
            // filters on the site's `serving_profile`.
            'types' => array_map(fn (BackupType $type): array => [
                'value' => $type->value,
                'label' => __('backup.type.'.$type->value),
                'family' => $type->isContainer() ? 'container' : 'hosted',
            ], BackupType::cases()),
            'retention' => ['min' => BackupTarget::RETENTION_MIN, 'max' => BackupTarget::RETENTION_MAX],
            // The clock `schedule_time` is read in. See BackupTarget::scheduleTimezone().
            'timezone' => (new BackupTarget)->scheduleTimezone(),
        ]);
    }

    /**
     * Every application and its backup configuration — the overview screen.
     *
     * Driven from applications, not from backup targets: listing targets would only return
     * sites that are already protected, and this screen exists to show which are not.
     *
     * Paged, but `meta` counts are not: they come from aggregate queries over every application,
     * so they describe the server whatever page or filter is active.
     */
    public function indexTargets(IndexBackupTargetsRequest $request): JsonResponse
    {
        $search = trim((string) $request->validated('search', ''));
        $filter = (array) $request->validated('filter', []);

        if ($request->validated('kind') === 'database') {
            return $this->indexDatabaseTargets($request, $search, $filter);
        }

        $applications = Application::query()
            ->with(['backupTarget.storageDestination', 'backupTarget.latestKeptBackup', 'backupTarget.latestRun'])
            ->when($search !== '', fn ($query) => ListSearch::apply($query, $search, ['name', 'domain']));

        $applications = $this->coverage($applications, 'application_id', $filter);

        $applications = $this->sortCoverage(
            $applications,
            'application_id',
            $request->validated('sort'),
            IndexBackupTargetsRequest::SORTS,
        )->paginate($request->validated('per_page', IndexBackupTargetsRequest::PER_PAGE));

        // One grouped query over every application: the four states add up to `total`.
        $states = BackupCoverage::counts(Application::query(), 'application_id');
        $total = array_sum($states);
        $configured = Application::query()->whereHas('backupTarget')->count();

        return response()->json([
            'backup_targets' => ApplicationBackupResource::collection($applications->items())->resolve(),
            'meta' => [
                'total' => $total,
                // The four BackupCoverage states; `unprotected` means no settings at all.
                'protected' => $states[BackupCoverage::PROTECTED],
                'unprotected' => $states[BackupCoverage::UNPROTECTED],
                'paused' => $states[BackupCoverage::PAUSED],
                'failing' => $states[BackupCoverage::FAILING],
                // Has backup settings at all, scheduled or not.
                'configured' => $configured,
                'current_page' => $applications->currentPage(),
                'per_page' => $applications->perPage(),
                // Rows matching the current search and filter; `total` is how many sites exist.
                'matched' => $applications->total(),
                'last_page' => $applications->lastPage(),
            ],
        ]);
    }

    /**
     * The backup settings for one application, or null when unconfigured.
     *
     * `has_volumes` lets the form disable `volumes` for a container site with none, which the
     * save refuses. Null when it does not apply (hosted site) or Docker could not be asked.
     */
    public function showTarget(Application $application, VolumeSizes $volumes): JsonResponse
    {
        $target = BackupTarget::with('storageDestination')
            ->where('application_id', $application->id)
            ->first();

        $found = $application->serving_profile === 'docker' ? $volumes->find($application) : null;

        return response()->json([
            'backup_target' => $target === null ? null : BackupTargetResource::make($target)->resolve(),
            'has_volumes' => $found === null ? null : $found !== [],
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
     * 204: there is nothing left to return.
     */
    public function destroy(Backup $backup, DeleteBackup $action): JsonResponse
    {
        $action->execute($backup);

        return response()->json(null, 204);
    }

    /**
     * Delete several backups at once.
     *
     * 200 with a per-backup outcome, because a batch can half-work. Same `succeeded`/`failed`
     * shape as the file manager's bulk operations.
     */
    public function destroyMany(BulkDeleteBackupsRequest $request, DeleteBackups $action): JsonResponse
    {
        // Oldest first, so a batch cut short has removed the backups least likely to be wanted.
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
     * The archives are the destructive part, so this refuses while any exist unless
     * `delete_backups` says otherwise, then deletes them as a single delete does.
     */
    public function destroyTarget(Application $application, DeleteBackupTarget $action): JsonResponse
    {
        $action->execute($application, request()->boolean('delete_backups'));

        return response()->json(null, 204);
    }

    /**
     * Run a backup now.
     *
     * 202 with the target, not the backup: the row does not exist until a worker picks the job up.
     */
    public function run(Application $application, StaleBackupReaper $reaper): JsonResponse
    {
        $target = BackupTarget::where('application_id', $application->id)->first();

        if ($target === null) {
            throw ValidationException::withMessages([
                'application' => [__('backup.errors.not_configured')],
            ]);
        }

        if (Restore::inProgressFor($target->application_id)) {
            throw ValidationException::withMessages([
                'application' => [__('backup.errors.restore_already_running')],
            ]);
        }

        // Also reaps abandoned rows: a run stranded by a killed worker would otherwise block the
        // site forever. The job is unique per target too; this tells the user why.
        if ($reaper->hasLiveRun($target)) {
            throw ValidationException::withMessages([
                'application' => [__('backup.errors.already_running')],
            ]);
        }

        $this->refuseVolumesWithoutVolumes($target);
        // Room for it first: said now, not by a failed backup.
        app(DiskSpaceCheck::class)->requireForBackup($target);

        RunBackup::dispatch($target->id, request()->user()?->id);

        return response()->json([
            'backup_target' => BackupTargetResource::make($target->fresh(['storageDestination']))->resolve(),
        ], 202);
    }

    /**
     * A `volumes` target of a site with no volumes, refused before it is queued.
     *
     * Targets saved before the save-time check still exist; a 202 for a run that can only
     * fail would hide it. A scheduled run fails in `ArchiveVolumes` with the same reason.
     */
    private function refuseVolumesWithoutVolumes(BackupTarget $target): void
    {
        if ($target->type !== BackupType::Volumes || $target->application === null) {
            return;
        }

        if (app(VolumeSizes::class)->hasNone($target->application)) {
            throw ValidationException::withMessages([
                'application' => [__('backup.errors.target_no_volumes')],
            ]);
        }
    }

    /**
     * Hand over a time-limited link to the archive itself.
     *
     * Presigned and returned as JSON; the panel never streams the bytes (that would pin a
     * PHP-FPM worker per multi-gigabyte transfer, and a 302 would send headers the signature
     * does not cover).
     *
     * SFTP and FTP have no such link and answer 409 `prepare_required`: the archive is copied
     * to the panel on the queue first and only that local copy is served
     * ({@see BackupDownloadController}). Never proxy from the storage server: the FTP
     * adapter buffers the whole archive before the first byte.
     *
     * Not gated on `verified` like restore: downloading changes nothing, and a failed run's
     * partial archive can help debugging.
     */
    public function download(Backup $backup, DestinationDisk $disks, StorageDriverFactory $drivers, ActivityLogger $activity): JsonResponse
    {
        $key = $backup->manifest['key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.download_no_artifact')],
            ]);
        }

        $destination = $backup->destination();

        if ($destination === null) {
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.download_no_destination')],
            ]);
        }

        // Asked of the *driver* before the disk: only it knows whether its provider has a link
        // (`temporaryUrl()` exists on the S3 adapter alone).
        $driver = $drivers->for($destination);

        // SFTP and FTP: the panel copies the archive here first (`POST …/download/prepare`) and
        // serves the copy. Answered before the disk is touched; no storage connection needed.
        if ($driver instanceof PreparesDownloads) {
            return response()->json([
                'message' => __('backup.errors.download_prepare_required'),
                'reason' => 'prepare_required',
            ], 409);
        }

        $driverUrl = $driver->downloadUrl($destination, $key);

        $disk = $disks->for($destination);

        if (! $disk->exists($key)) {
            // The row says the archive exists; the bucket disagrees.
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.download_missing')],
            ]);
        }

        // Short: long enough to start the transfer, too short to be worth passing around.
        $expiresAt = now()->addMinutes(5);

        // Before the log entry: making it can still be refused (no signed links), and a
        // refused download must not be logged as "Downloaded".
        $url = $driverUrl ?? $this->signedUrl($disk, $key, $expiresAt);

        // The audit trail records who asked, never the URL: it is a working credential.
        $activity->log('backup.downloaded', $backup, [
            'application' => $backup->subjectName(),
            'backup_id' => $backup->getKey(),
        ]);

        return response()->json([
            'download' => [
                'url' => $url,

                // Only for a signed URL: a Drive link does not expire, it is gated on who is signed in.
                'expires_at' => $driverUrl !== null ? null : $expiresAt->format('d-m-Y H:i:s'),
                'filename' => $backup->downloadFilename(),
                'size_bytes' => $backup->size_bytes,
            ],
        ]);
    }

    /**
     * The S3 path: a signed link that carries its own expiry.
     *
     * Guarded: `temporaryUrl()` throws a RuntimeException on every adapter but S3, which would
     * surface as a 500, so a driver that cannot sign gets a named reason instead.
     */
    private function signedUrl(Filesystem $disk, string $key, CarbonInterface $expiresAt): string
    {
        if (! $disk instanceof FilesystemAdapter || ! $disk->providesTemporaryUrls()) {
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.download_not_supported')],
            ]);
        }

        return $disk->temporaryUrl($key, $expiresAt);
    }

    /** Poll one backup while it runs. */
    public function show(Backup $backup): JsonResponse
    {
        // Loaded so a polled row carries the same fields as a row from the list.
        return response()->json([
            'backup' => BackupResource::make($backup->load('storageDestination:id,name'))->resolve(),
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

        $restoring = $target->isDatabaseTarget()
            ? Restore::inProgressForDatabase($target->database_id)
            : Restore::inProgressFor($target->application_id);

        if ($restoring) {
            throw ValidationException::withMessages([
                'application' => [__('backup.errors.restore_already_running')],
            ]);
        }

        if ($reaper->hasLiveRun($target)) {
            throw ValidationException::withMessages([
                'application' => [__('backup.errors.already_running')],
            ]);
        }

        $this->refuseVolumesWithoutVolumes($target);
        // Room for it first: said now, not by a failed backup.
        app(DiskSpaceCheck::class)->requireForBackup($target);

        RunBackup::dispatch($target->id, request()->user()?->id);

        return response()->json([
            'backup_target' => BackupTargetResource::make($target->fresh(['storageDestination']))->resolve(),
        ], 202);
    }

    /**
     * Close out a run that is stuck in flight.
     *
     * Automatic reaping only happens when somebody next asks for a backup, and a run is
     * provably dead only after over an hour; this is the manual button.
     *
     * **A run that could still be alive is refused.** A running job cannot be killed from here,
     * so marking it failed would free the guard for a second backup writing to the same disk
     * and archive key.
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

        $activity->log('backup.cleared', $backup->application ?? $backup->database ?? $backup, [
            'name' => $backup->subjectName(),
        ]);

        return response()->json([
            'backup' => BackupResource::make($backup->fresh())->resolve(),
        ]);
    }

    /**
     * The database half of the filters: a database's own backups, archived ones (no site and no
     * database any more), and the engine they hold.
     *
     * @param  array<string, mixed>  $filter
     */
    private function subjectFilters(Builder $query, array $filter, ?bool $archived): void
    {
        $query
            ->when($filter['database_id'] ?? null, fn ($q, $id) => $q->where('database_id', $id))
            ->when($archived === true, fn ($q) => $q->whereNull('application_id')->whereNull('database_id'))
            ->when($archived === false, fn ($q) => $q->where(fn ($q) => $q->whereNotNull('application_id')->orWhereNotNull('database_id')))
            ->when($filter['engine'] ?? null, fn ($q, $engine) => $q->where('subject_engine', $engine));
    }

    /**
     * The overview's database rows: every database that is not part of a site (a site's
     * database is backed up with the site). Same filters, paging and counts as the applications.
     *
     * @param  array<string, mixed>  $filter
     */
    private function indexDatabaseTargets(IndexBackupTargetsRequest $request, string $search, array $filter): JsonResponse
    {
        $own = fn () => Database::query()->whereNull('application_id');

        $databases = $own()
            ->with(['backupTarget.storageDestination', 'backupTarget.latestKeptBackup', 'backupTarget.latestRun'])
            ->when($search !== '', fn ($query) => ListSearch::apply($query, $search, ['name']));

        $databases = $this->coverage($databases, 'database_id', $filter);

        $databases = $this->sortCoverage(
            $databases,
            'database_id',
            $request->validated('sort'),
            IndexBackupTargetsRequest::DATABASE_SORTS,
        )->paginate($request->validated('per_page', IndexBackupTargetsRequest::PER_PAGE));

        $states = BackupCoverage::counts($own(), 'database_id');
        $total = array_sum($states);

        return response()->json([
            'backup_targets' => DatabaseBackupOverviewResource::collection($databases->items())->resolve(),
            'meta' => [
                'kind' => 'database',
                'total' => $total,
                'protected' => $states[BackupCoverage::PROTECTED],
                'unprotected' => $states[BackupCoverage::UNPROTECTED],
                'paused' => $states[BackupCoverage::PAUSED],
                'failing' => $states[BackupCoverage::FAILING],
                'configured' => $own()->whereHas('backupTarget')->count(),
                'current_page' => $databases->currentPage(),
                'per_page' => $databases->perPage(),
                'matched' => $databases->total(),
                'last_page' => $databases->lastPage(),
            ],
        ]);
    }

    /**
     * The coverage list's filters, all through BackupCoverage so a row's `state` and the filter
     * that found it are the same rule.
     *
     * `filter[protected]`: `1` is protected sites only; `0` is everything else (unprotected,
     * paused AND failing).
     *
     * @param  array<string, mixed>  $filter
     */
    private function coverage(Builder $query, string $ownerColumn, array $filter): Builder
    {
        BackupCoverage::select($query, $ownerColumn);

        if (array_key_exists('protected', $filter) && $filter['protected'] !== null) {
            BackupCoverage::only($query, $ownerColumn, $filter['protected']
                ? [BackupCoverage::PROTECTED]
                : [BackupCoverage::UNPROTECTED, BackupCoverage::PAUSED, BackupCoverage::FAILING]);
        }

        if (! empty($filter['state'])) {
            BackupCoverage::only($query, $ownerColumn, (array) $filter['state']);
        }

        if (! empty($filter['type'])) {
            $query->whereHas('backupTarget', fn (Builder $target) => $target->whereIn('type', (array) $filter['type']));
        }

        return $query;
    }

    /**
     * `sort=state` (the default) or `-state` through BackupCoverage; any other whitelisted
     * column through ListSort.
     *
     * @param  list<string>  $columns
     */
    private function sortCoverage(Builder $query, string $ownerColumn, ?string $sort, array $columns): Builder
    {
        $sort = trim((string) $sort);

        if ($sort === '' || ltrim($sort, '-') === 'state') {
            return BackupCoverage::sort($query, $ownerColumn, str_starts_with($sort, '-'));
        }

        return ListSort::apply($query, $sort, $columns, 'asc', caseInsensitive: ['name']);
    }
}
