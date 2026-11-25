<?php

namespace App\Services\Server\Applications;

use App\Contracts\StagingStrategy;
use App\Enums\DomainType;
use App\Exceptions\NotEnoughBackupSpace;
use App\Exceptions\Server\Application\StagingOperationException;
use App\Exceptions\Server\Application\StagingRollbackException;
use App\Exceptions\Server\ServerOperationException;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Models\Database;
use App\Models\Restore;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Backups\LocalSpaceGuard;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\DatabaseSizes;
use App\Services\Server\ServerOps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Everything about staging that is the same whatever is staged: the `Application` row, its vhost,
 * copying files, the maintenance-mode window and a pre-push database safety dump. The
 * type-specific part (does it need a database, how to point it at the right one) is delegated to
 * that type's `StagingStrategy`.
 */
class StagingManager
{
    public function __construct(
        private ApplicationProvisioner $provisioner,
        private DatabaseManager $databases,
        private SiteTypeManager $siteTypes,
        private ServerOps $serverOps,
        private LocalSpaceGuard $space,
        private DatabaseSizes $sizes,
    ) {}

    public function create(Application $production, string $domain): Application
    {
        $strategy = $this->strategyFor($production);

        if ($strategy === null) {
            throw new StagingOperationException((string) Str::uuid());
        }

        if ($production->staging !== null) {
            throw new StagingOperationException((string) Str::uuid());
        }

        $name = Application::uniqueName("{$production->name} (Staging)");

        // forceCreate, not create: `slug` is not fillable, and without one the staging document
        // root collapses onto the system user's home.
        $staging = Application::forceCreate([
            'system_user_id' => $production->system_user_id,
            'production_application_id' => $production->id,
            'name' => $name,
            'slug' => Application::uniqueSlug(
                $name,
                alsoTaken: fn (string $slug): bool => app(SlugConflict::class)->for($slug) !== null,
            ),
            'domain' => $domain,
            'site_type' => $production->site_type,
            'serving_profile' => $production->serving_profile,
            'php_version' => $production->php_version,
            'web_root' => $production->web_root,
            'status' => 'pending',
        ]);

        // The Domains screen reads `application_domains`; `applications.domain` only mirrors the
        // primary row, and this path bypasses CreateApplication. Without the row,
        // `Application::serverNames()` falls back to the column only while the relation is empty,
        // so adding an alias later would drop the site's own domain from the vhost.
        $staging->domains()->create([
            'domain' => strtolower(trim((string) $staging->domain)),
            'type' => DomainType::Primary,
            // A generated staging hostname is usually wildcard DNS; certificate actions filter on
            // this flag to avoid spending the shared nip.io rate limit.
            'is_test' => ApplicationDomain::looksTemporary((string) $staging->domain),
        ]);

        $staging->load('systemUser');

        // Everything from here can fail on the server and the row already exists; left behind it
        // would hold the domain and block every later attempt. See `discard()`.
        try {
            // skipInstaller=true: staging is provisioned from production's copied files; the
            // marketplace installer would run `wp core install` against an existing site (as in
            // CloneManager).
            $this->provisioner->provision($staging, skipInstaller: true);

            // Same exclusions in this direction, so a file holding production's credentials never
            // exists under staging's document root, even briefly.
            $this->rsync(
                $this->provisioner->documentRoot($production->load('systemUser')),
                $this->provisioner->documentRoot($staging),
                $staging,
                $strategy->syncExcludes(),
                // Media crosses in this direction too, or staging starts with broken images.
                $strategy->mergePaths(),
            );

            $strategy->create($production, $staging);
        } catch (Throwable $e) {
            $this->discard($staging);

            throw $e;
        }

        $staging->status = 'active';
        $staging->save();

        return $staging->fresh();
    }

    /**
     * Undo a half-made site.
     *
     * Goes through the provisioner, not `DeprovisionApplication`, which returns early for a
     * `pending` application and would skip the vhost already written. Cleanup failures are
     * swallowed so they do not hide the error that made the caller throw.
     */
    private function discard(Application $application): void
    {
        try {
            $this->provisioner->deprovision($application);
        } catch (Throwable $cleanupFailure) {
            Log::channel('server-ops')->warning('could not deprovision a half-made site', [
                'feature' => 'application',
                'op' => 'staging_discard',
                'application' => $application->id,
                'detail' => $cleanupFailure->getMessage(),
            ]);
        }

        // The row is what blocks a retry: it holds the domain, name, slug and any port.
        $application->delete();
    }

    /**
     * @param  'files'|'full'  $mode
     */
    /** Pre-push database dumps kept per site, newest first. */
    public const KEEP_PRE_PUSH_DUMPS = 3;

    /**
     * Push staging onto production: one at a time per site, never during a restore of it.
     *
     * The lock stops a double-click from running two overlapping pushes; the marker is what the
     * backup job reads, so a scheduled backup does not archive a half-overwritten site.
     */
    public function push(Application $production, string $mode, bool $safetyCopy = true): void
    {
        if (Restore::inProgressFor($production->id)) {
            throw ValidationException::withMessages([
                'mode' => [__('backup.errors.restore_already_running')],
            ]);
        }

        $lock = Cache::lock(self::lockKey($production->id), 3600);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'mode' => [__('errors/application.staging_push_running')],
            ]);
        }

        Cache::put(self::markerKey($production->id), true, 3600);

        try {
            $this->runPush($production, $mode, $safetyCopy);
        } finally {
            Cache::forget(self::markerKey($production->id));
            $lock->release();
        }

        $this->pruneSafetyDumps($production);
    }

    /**
     * Update staging from live: the reverse of a push.
     *
     * Staging's files and/or database are replaced with live's, and staging stays staging: its
     * own wp-config (database, URL, cron off), mail trap and noindex are never overwritten and
     * the strategy re-asserts them after. Staging is snapshotted and its database dumped first,
     * and a failure puts both back. Live is only read, never disabled.
     *
     * Both sites are locked: live's lock is the push lock, so a push and a pull of the same pair
     * cannot overlap; staging's is the one its own safety-copy restore takes.
     */
    public function pull(Application $production, string $mode, bool $safetyCopy = true): void
    {
        $staging = $production->staging;

        if ($staging === null || $this->strategyFor($production) === null) {
            throw new StagingOperationException((string) Str::uuid());
        }

        if (Restore::inProgressFor($production->id) || Restore::inProgressFor($staging->id)) {
            throw ValidationException::withMessages([
                'mode' => [__('backup.errors.restore_already_running')],
            ]);
        }

        $liveLock = Cache::lock(self::lockKey($production->id), 3600);

        if (! $liveLock->get()) {
            throw ValidationException::withMessages([
                'mode' => [__('errors/application.staging_push_running')],
            ]);
        }

        $stagingLock = Cache::lock(self::lockKey($staging->id), 3600);

        if (! $stagingLock->get()) {
            $liveLock->release();

            throw ValidationException::withMessages([
                'mode' => [__('errors/application.staging_push_running')],
            ]);
        }

        Cache::put(self::markerKey($staging->id), true, 3600);

        try {
            $strategy = $this->strategyFor($production);
            $this->replace($staging, $production, $mode, fn () => $strategy->pull($production, $staging, $mode), $safetyCopy);
        } finally {
            Cache::forget(self::markerKey($staging->id));
            $stagingLock->release();
            $liveLock->release();
        }

        $this->pruneSafetyDumps($staging);
    }

    /**
     * The pre-push copies of the live database, newest first.
     *
     * Each push that replaces the database writes one; three are kept.
     *
     * @return list<array{name: string, size_bytes: int, created_at: string}>
     */
    public function safetyDumps(Application $production): array
    {
        $listed = $this->serverOps->run(
            ['find', $production->panelPath().'/staging-backups', '-mindepth', '1', '-maxdepth', '1', '-type', 'f', '-name', 'pre-push-*.sql', '-printf', '%f\t%s\t%T@\n'],
            ['feature' => 'application', 'op' => 'staging_dump_list', 'application' => $production->id],
            timeout: 30,
            expectedExitCodes: [1],
        );

        // No directory yet means no copies, not an error (`find` exits 1 for a missing path).
        if ($listed->failed()) {
            return [];
        }

        $dumps = [];

        foreach (explode("\n", $listed->output()) as $line) {
            $fields = explode("\t", trim($line));

            if (count($fields) !== 3 || ! self::isSafetyDumpName($fields[0])) {
                continue;
            }

            $dumps[] = [
                'name' => $fields[0],
                'size_bytes' => (int) $fields[1],
                'created_at' => Carbon::createFromTimestamp((int) $fields[2])->toIso8601String(),
            ];
        }

        usort($dumps, fn (array $a, array $b) => strcmp($b['name'], $a['name']));

        return $dumps;
    }

    /**
     * Put one pre-push copy back into the live database.
     *
     * Under the push lock, and refused during a backup restore. The current database is dumped
     * first, like a push does, so this is undoable from the same list.
     */
    public function restoreSafetyDump(Application $production, string $name): void
    {
        if (! self::isSafetyDumpName($name) || ! in_array($name, array_column($this->safetyDumps($production), 'name'), true)) {
            throw ValidationException::withMessages([
                'name' => [__('errors/application.staging_safety_copy_missing')],
            ]);
        }

        $database = Database::where('application_id', $production->id)->first();

        if ($database === null) {
            throw ValidationException::withMessages([
                'name' => [__('errors/application.staging_safety_copy_no_database')],
            ]);
        }

        if (Restore::inProgressFor($production->id)) {
            throw ValidationException::withMessages([
                'name' => [__('backup.errors.restore_already_running')],
            ]);
        }

        $lock = Cache::lock(self::lockKey($production->id), 3600);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'name' => [__('errors/application.staging_push_running')],
            ]);
        }

        Cache::put(self::markerKey($production->id), true, 3600);

        try {
            $this->safetyDump($production);
            $this->databases->engine($database->engine)->restore($database->name, $production->panelPath().'/staging-backups/'.$name);
        } finally {
            Cache::forget(self::markerKey($production->id));
            $lock->release();
        }

        $this->pruneSafetyDumps($production);
    }

    /** What `safetyDump()` writes, and nothing else — the name reaches a path. */
    public static function isSafetyDumpName(string $name): bool
    {
        return preg_match('/\Apre-push-\d{8}-\d{6}-[a-z0-9]{6}\.sql\z/', $name) === 1;
    }

    public static function pushInProgress(int $applicationId): bool
    {
        return Cache::has(self::markerKey($applicationId));
    }

    /**
     * Whether something holds this site's push lock right now (taken and released at once): a
     * push or pull runs on the queue, so the request must refuse a second one up front.
     */
    public static function locked(int $applicationId): bool
    {
        $lock = Cache::lock(self::lockKey($applicationId), 5);

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }

    private static function lockKey(int $applicationId): string
    {
        return "staging-push:{$applicationId}";
    }

    private static function markerKey(int $applicationId): string
    {
        return "staging-push-running:{$applicationId}";
    }

    /**
     * Keep the newest few pre-push dumps and remove the rest.
     *
     * Each is a full copy of the production database, so unpruned they fill the disk. Best-effort:
     * a push that succeeded is not failed by housekeeping.
     */
    private function pruneSafetyDumps(Application $production): void
    {
        $directory = $production->panelPath().'/staging-backups';
        $context = ['feature' => 'application', 'op' => 'staging_dump_prune', 'application' => $production->id];

        // `.panel` and this directory are root's (PanelDirectory) and `-type f` skips links.
        $listed = $this->serverOps->run(
            ['find', $directory, '-mindepth', '1', '-maxdepth', '1', '-type', 'f', '-name', 'pre-push-*.sql', '-printf', '%f\n'],
            $context,
            timeout: 30,
        );

        if ($listed->failed()) {
            return;
        }

        // The name starts with the timestamp, so a reverse sort is newest first.
        $names = array_values(array_filter(array_map('trim', explode("\n", $listed->output()))));
        rsort($names, SORT_STRING);

        $expired = array_slice($names, self::KEEP_PRE_PUSH_DUMPS);

        if ($expired !== []) {
            $this->serverOps->run(
                array_merge(['rm', '-f', '--'], array_map(fn (string $name) => "{$directory}/{$name}", $expired)),
                $context,
                timeout: 60,
            );
        }
    }

    private function runPush(Application $production, string $mode, bool $safetyCopy = true): void
    {
        $staging = $production->staging;

        if ($staging === null) {
            throw new StagingOperationException((string) Str::uuid());
        }

        $strategy = $this->strategyFor($production);

        if ($strategy === null) {
            throw new StagingOperationException((string) Str::uuid());
        }

        $this->replace($production, $staging, $mode, fn () => $strategy->push($production, $staging, $mode), $safetyCopy);
    }

    /**
     * Replace `$target`'s files and/or database with `$source`'s — a push
     * (target = live) or a pull (target = staging). The target is snapshotted
     * and its database dumped first, disabled while it is swapped, and put
     * back from those copies if anything fails; the source is only read.
     *
     * @param  callable(): void  $strategyStep  the type's database half
     */
    private function replace(Application $target, Application $source, string $mode, callable $strategyStep, bool $safetyCopy = true): void
    {
        // Both sites are the same type, so either answers.
        $strategy = $this->strategyFor($target);

        if ($strategy === null) {
            throw new StagingOperationException((string) Str::uuid());
        }

        $target->load('systemUser');
        $source->load('systemUser');

        // Each mode moves the files, the database, or both.
        $pushesFiles = in_array($mode, ['files', 'full'], true);
        $pushesDatabase = in_array($mode, ['database', 'full'], true);

        $rollbackDirectory = $target->panelPath().'/staging-rollbacks/'.Str::uuid();
        $fileSnapshot = $rollbackDirectory.'/files';
        $databaseSnapshot = null;

        // No file snapshot for a database-only push: nothing writes to the document root.
        if ($pushesFiles) {
            try {
                $this->snapshotFiles($target, $fileSnapshot);
            } catch (Throwable $failure) {
                $this->discardFileSnapshot($target, $rollbackDirectory);

                throw $failure;
            }
        }

        // Any mode that replaces the database dumps it first (it holds orders and customers and
        // cannot restore itself). A files-only push skips it: its post-push work writes only
        // transients and `rewrite_rules`, which WordPress regenerates. With the safety copy turned
        // off a database that fails part-way is not put back; files still are, from a snapshot
        // kept only while the swap runs.
        if ($pushesDatabase && $safetyCopy) {
            try {
                $databaseSnapshot = $this->safetyDump($target);
            } catch (Throwable $failure) {
                $this->discardFileSnapshot($target, $rollbackDirectory);

                throw $failure;
            }
        }

        // A visitor mid-checkout must never see a half-swapped site: reuse the "unavailable" page
        // of enable/disable.
        try {
            $this->provisioner->disable($target);
        } catch (Throwable $failure) {
            $this->discardFileSnapshot($target, $rollbackDirectory);

            throw $failure;
        }

        try {
            if ($pushesFiles) {
                $this->rsync(
                    $this->provisioner->documentRoot($source),
                    $this->provisioner->documentRoot($target),
                    $target,
                    $strategy->syncExcludes(),
                    $strategy->mergePaths(),
                );
            }

            $strategyStep();
            $this->provisioner->enable($target);
        } catch (Throwable $pushFailure) {
            try {
                // Only restore halves that were snapshotted: restoring files after a database-only
                // push would replace the live document root with nothing.
                if ($pushesFiles) {
                    $this->restoreFiles($target, $fileSnapshot);
                }

                if ($databaseSnapshot !== null) {
                    $database = $databaseSnapshot['database'];
                    $this->databases->engine($database->engine)->restore($database->name, $databaseSnapshot['path']);
                }

                $target->refresh();

                if ($target->disabled_at !== null) {
                    $this->provisioner->enable($target);
                }

                $this->discardFileSnapshot($target, $rollbackDirectory);
            } catch (Throwable $rollbackFailure) {
                $reference = $this->failureReference($rollbackFailure);

                Log::channel('server-ops')->critical('staging push rollback failed', [
                    'feature' => 'application',
                    'op' => 'staging_push_rollback',
                    'application' => $target->id,
                    'reference' => $reference,
                    'push_reference' => $this->failureReference($pushFailure),
                    'rollback_reference' => $reference,
                    'rollback_directory' => $rollbackDirectory,
                ]);

                throw new StagingRollbackException($reference);
            }

            throw $pushFailure;
        }

        $this->discardFileSnapshot($target, $rollbackDirectory);
    }

    private function strategyFor(Application $production): ?StagingStrategy
    {
        return $this->siteTypes->find($production->site_type)?->stagingStrategy();
    }

    /**
     * @param  array<int, string>  $patterns
     * @param  array<int, string>  $mergePaths  Copied in a second, additive
     *                                          pass — see
     *                                          `StagingStrategy::mergePaths()`.
     */
    private function rsync(string $source, string $destination, Application $owner, array $patterns, array $mergePaths = []): void
    {
        // Which files carry a site's own identity is a question only the site type can answer.
        $excludes = $patterns === []
            ? []
            : array_merge(...array_map(fn (string $pattern) => ['--exclude', $pattern], $patterns));

        $result = $this->serverOps->run(
            array_merge(['rsync', '-a', '--delete'], $excludes, [rtrim($source, '/').'/', rtrim($destination, '/').'/']),
            ['feature' => 'application', 'op' => 'staging_rsync', 'application' => $owner->id],
            timeout: 300,
        );

        if ($result->failed()) {
            throw new StagingOperationException($result->reference);
        }

        // Before the chown below: these passes write as root, and a root-owned image cannot be
        // replaced or deleted by the site user afterwards.
        foreach ($mergePaths as $path) {
            $this->merge($source, $destination, $owner, $path);
        }

        $ownership = $this->serverOps->run(
            ['chown', '-R', "{$owner->systemUser->username}:{$owner->systemUser->username}", $destination],
            ['feature' => 'application', 'op' => 'staging_rsync_chown', 'application' => $owner->id],
        );

        if ($ownership->failed()) {
            throw new StagingOperationException($ownership->reference, $ownership->busy, $ownership->staleLock);
        }
    }

    /**
     * One additive pass: copy `$path` across, remove nothing.
     *
     * No `--delete`: a push must not erase what is already on the other side.
     *
     * The source is probed first because a site with no uploads has no `wp-content/uploads` and
     * rsync fails on a missing source. `probe()`, not `run()`, so "not there" stays distinct from
     * "the panel was not allowed to ask": a sudo refusal must fail the push, not skip the media.
     */
    private function merge(string $source, string $destination, Application $owner, string $path): void
    {
        $from = rtrim($source, '/').'/'.trim($path, '/');
        $to = rtrim($destination, '/').'/'.trim($path, '/');

        $exists = $this->serverOps->probe(
            ['test', '-d', $from],
            ['feature' => 'application', 'op' => 'staging_merge_probe', 'application' => $owner->id],
        );

        if (! $exists->answered) {
            throw new StagingOperationException($exists->reference, $exists->busy, $exists->staleLock);
        }

        if (! $exists->ok) {
            return;
        }

        // mkdir -p: rsync creates the leaf but not the path above it, and merge paths are not
        // WordPress-only.
        $directory = $this->serverOps->run(
            ['mkdir', '-p', $to],
            ['feature' => 'application', 'op' => 'staging_merge_dir', 'application' => $owner->id],
        );

        if ($directory->failed()) {
            throw new StagingOperationException($directory->reference, $directory->busy, $directory->staleLock);
        }

        $merged = $this->serverOps->run(
            ['rsync', '-a', $from.'/', $to.'/'],
            ['feature' => 'application', 'op' => 'staging_merge', 'application' => $owner->id],
            timeout: 300,
        );

        if ($merged->failed()) {
            throw new StagingOperationException($merged->reference, $merged->busy, $merged->staleLock);
        }
    }

    private function snapshotFiles(Application $production, string $destination): void
    {
        $directory = $this->serverOps->run(
            ['mkdir', '-p', $destination],
            ['feature' => 'application', 'op' => 'staging_snapshot_dir', 'application' => $production->id],
        );

        if ($directory->failed()) {
            throw new StagingOperationException($directory->reference, $directory->busy, $directory->staleLock);
        }

        $source = rtrim($this->provisioner->documentRoot($production), '/');

        // Room first: the snapshot is a whole copy of the site's files for as long as the push
        // runs, and a full disk takes every site down. `du` still prints a total when it cannot
        // read a few entries; no total at all is let through, as for backups.
        $measured = $this->serverOps->run(
            ['du', '-sb', '--', $source],
            ['feature' => 'application', 'op' => 'staging_snapshot_size', 'application' => $production->id],
            timeout: 120,
        );
        $bytes = (int) strtok(trim($measured->output()), "\t");

        try {
            $this->space->ensureRoomFor($destination, $bytes);
        } catch (NotEnoughBackupSpace) {
            throw ValidationException::withMessages([
                'mode' => [__('errors/application.staging_files_not_enough_space')],
            ]);
        }

        $snapshot = $this->serverOps->run(
            ['rsync', '-a', '--delete', $source.'/', rtrim($destination, '/').'/'],
            ['feature' => 'application', 'op' => 'staging_snapshot', 'application' => $production->id],
            timeout: 300,
        );

        if ($snapshot->failed()) {
            throw new StagingOperationException($snapshot->reference, $snapshot->busy, $snapshot->staleLock);
        }
    }

    private function restoreFiles(Application $production, string $snapshot): void
    {
        $destination = $this->provisioner->documentRoot($production);
        $restored = $this->serverOps->run(
            ['rsync', '-a', '--delete', rtrim($snapshot, '/').'/', rtrim($destination, '/').'/'],
            ['feature' => 'application', 'op' => 'staging_restore_files', 'application' => $production->id],
            timeout: 300,
        );

        if ($restored->failed()) {
            throw new StagingOperationException($restored->reference, $restored->busy, $restored->staleLock);
        }

        $ownership = $this->serverOps->run(
            ['chown', '-R', "{$production->systemUser->username}:{$production->systemUser->username}", $destination],
            ['feature' => 'application', 'op' => 'staging_restore_chown', 'application' => $production->id],
        );

        if ($ownership->failed()) {
            throw new StagingOperationException($ownership->reference, $ownership->busy, $ownership->staleLock);
        }
    }

    private function discardFileSnapshot(Application $production, string $directory): void
    {
        $removed = $this->serverOps->run(
            ['rm', '-rf', $directory],
            ['feature' => 'application', 'op' => 'staging_snapshot_cleanup', 'application' => $production->id],
        );

        if ($removed->failed()) {
            Log::channel('server-ops')->warning('could not remove staging rollback files', [
                'feature' => 'application',
                'op' => 'staging_snapshot_cleanup',
                'application' => $production->id,
                'reference' => $removed->reference,
                'rollback_directory' => $directory,
            ]);
        }
    }

    private function failureReference(Throwable $failure): string
    {
        return $failure instanceof ServerOperationException
            ? $failure->reference
            : (string) Str::uuid();
    }

    /**
     * A local DB dump kept on the site's own disk before a push overwrites production's database:
     * a same-box safety net for the one irreversible part of a push, not a substitute for Backups.
     *
     * @return array{database: Database, path: string}|null
     */
    private function safetyDump(Application $production): ?array
    {
        $database = Database::where('application_id', $production->id)->first();

        if ($database === null) {
            return null;
        }

        // Above the document root: a full production dump inside the served directory would be
        // protected only by a vhost deny rule.
        $directory = $production->panelPath().'/staging-backups';
        $path = $directory.'/pre-push-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.sql';

        $created = $this->serverOps->run(
            ['mkdir', '-p', $directory],
            ['feature' => 'application', 'op' => 'staging_safety_dir', 'application' => $production->id],
        );

        if ($created->failed()) {
            throw new StagingOperationException($created->reference, $created->busy, $created->staleLock);
        }

        // Room first: a full disk takes every site down. Measured now, not read from the stored
        // size, which can be days old. Unknown size or an unmeasurable disk is let through.
        $bytes = (int) $this->sizes->refresh($database)->size_bytes;

        try {
            $this->space->ensureRoomFor($directory, $bytes);
        } catch (NotEnoughBackupSpace) {
            throw ValidationException::withMessages([
                'safety_copy' => [__('errors/application.staging_not_enough_space')],
            ]);
        }

        try {
            $this->databases->engine($database->engine)->dump($database->name, $path);
        } catch (Throwable $e) {
            // A dump that died part-way (likely out of space) cannot be restored and holds the space.
            $this->serverOps->run(
                ['rm', '-f', '--', $path],
                ['feature' => 'application', 'op' => 'staging_safety_dump_cleanup', 'application' => $production->id],
            );

            throw $e;
        }

        return ['database' => $database, 'path' => $path];
    }
}
