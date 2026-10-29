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
 * Everything about staging that is the same no matter what is being staged:
 * creating the `Application` row, provisioning its vhost, copying files, the
 * maintenance-mode window, and a pre-push database safety dump. Only the
 * type-specific 20% — "does this need a database, and how do I point it at
 * the right one" — is delegated to that type's `StagingStrategy` (see
 * `App\Contracts\StagingStrategy`).
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

        // forceCreate, not create: `slug` is not fillable, and without one the
        // staging site's document root collapses onto the system user's home
        // — the same directory production's own clone would land in.
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

        // The Domains screen reads `application_domains`, not this column —
        // `applications.domain` is only the mirror of whichever row is
        // primary. CreateApplication writes that row; this path does not go
        // through it, so a staging site came up serving its domain with a
        // completely empty Domains section.
        //
        // Worse than cosmetic. `Application::serverNames()` falls back to this
        // column only while the relation is empty, so adding a single alias
        // afterwards made the relation non-empty *without* the primary in it —
        // and the next vhost dropped the site's own domain.
        $staging->domains()->create([
            'domain' => strtolower(trim((string) $staging->domain)),
            'type' => DomainType::Primary,
            // A generated staging hostname is usually a wildcard-DNS name, and the
            // certificate actions filter on this flag to avoid spending the
            // shared nip.io rate limit.
            'is_test' => ApplicationDomain::looksTemporary((string) $staging->domain),
        ]);

        $staging->load('systemUser');

        // Everything from here can fail on the server, and the row already
        // exists. Left behind, that row holds the domain and satisfies the
        // `$production->staging !== null` guard above — so a staging attempt
        // that failed once blocked every later attempt, and the panel listed a
        // site that was never created. See `discard()`.
        try {
            // skipInstaller=true: staging is provisioned from production's copied
            // files, not from a fresh installation. Running the marketplace
            // installer would call `wp core install` against a site that already
            // has a database and configuration — the same bug CloneManager already
            // avoids with the same flag.
            $this->provisioner->provision($staging, skipInstaller: true);

            // Same exclusions in this direction. `create()` writes staging's
            // own wp-config immediately afterwards, but excluding it here
            // means the file never exists holding production's credentials
            // under staging's document root, even for a moment.
            $this->rsync(
                $this->provisioner->documentRoot($production->load('systemUser')),
                $this->provisioner->documentRoot($staging),
                $staging,
                $strategy->syncExcludes(),
                // Media crosses in this direction too, and for the same
                // reason: without it a staging site cloned from a live shop
                // starts with every existing image broken.
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
     * Deprovision goes through the provisioner rather than
     * `DeprovisionApplication`, which returns early for a `pending`
     * application — and a site that failed on the way up is exactly that, so
     * the action would skip the vhost it did manage to write.
     *
     * Cleanup failures are swallowed on purpose. The caller is already
     * throwing the reason the site could not be created, and replacing that
     * with "cleanup failed" would hide the thing the user needs to read.
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

        // The row is what actually blocks a retry: it holds the domain, the
        // name, the slug and any allocated port.
        $application->delete();
    }

    /**
     * @param  'files'|'full'  $mode
     */
    /** Pre-push database dumps kept per site, newest first. */
    public const KEEP_PRE_PUSH_DUMPS = 3;

    /**
     * Push staging onto production — one at a time per site, never during a
     * restore of it.
     *
     * It ran inside the request with no lock: a double-click started two
     * pushes that snapshot, overwrite and roll back the same production site
     * at once, and nothing stopped one running while a restore swapped the
     * same files (found in code review 2026-09-29). The marker is what the
     * backup job reads, so a scheduled backup does not archive a site that
     * is half-overwritten either.
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
     * Update staging from live — the reverse of a push (v7's sync-to-staging).
     *
     * Staging's files and/or database are replaced with live's, and staging
     * stays staging: its own wp-config (database, URL, cron off), mail trap and
     * noindex are never overwritten, and the strategy re-asserts them after.
     *
     * v7 did this with no copy of staging and with live's config copied over
     * staging's — a non-WordPress staging site came out connected to the live
     * database. Here staging is snapshotted and its database dumped first, and
     * a failure puts both back. Live is only read, never disabled.
     *
     * Both sites are locked: live's lock is the one a push takes, so a push and
     * a pull of the same pair cannot run together; staging's is the one its own
     * safety-copy restore takes.
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
     * ST-B3: the pre-push copies of the live database, newest first.
     *
     * Each push that replaces the database writes one, and three are kept —
     * but nothing could list them, so the way back existed only for someone
     * with a shell on the server.
     *
     * @return list<array{name: string, size_bytes: int, created_at: string}>
     */
    public function safetyDumps(Application $production): array
    {
        $listed = $this->serverOps->run(
            ['find', $production->panelPath().'/staging-backups', '-mindepth', '1', '-maxdepth', '1', '-type', 'f', '-name', 'pre-push-*.sql', '-printf', '%f\t%s\t%T@\n'],
            ['feature' => 'application', 'op' => 'staging_dump_list', 'application' => $production->id],
            timeout: 30,
        );

        // No directory yet is no copies, not an error: a site that never
        // pushed its database has none.
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
     * ST-B3: put one pre-push copy back into the live database.
     *
     * Under the push lock, so it cannot run while a push is replacing the same
     * database, and refused during a backup restore. The database as it is now
     * is dumped first, the same safety copy a push takes, so this is undoable
     * from the same list.
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
     * Whether something holds this site's push lock right now — asked by
     * taking it and letting go at once (RC-16): a push or pull now runs on
     * the queue, and the request has to refuse a second one up front rather
     * than queue a run that can only fail.
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
     * Each is a full copy of the production database, written before every
     * push and never removed, so a site that pushed often slowly filled the
     * disk. The newest are kept as a manual way back. Best-effort: a push
     * that succeeded is not failed by housekeeping.
     */
    private function pruneSafetyDumps(Application $production): void
    {
        $directory = $production->panelPath().'/staging-backups';
        $context = ['feature' => 'application', 'op' => 'staging_dump_prune', 'application' => $production->id];

        // `.panel` and this directory are root's (PanelDirectory), and -type f
        // matches only real files, so nothing here follows a link.
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

        // Each mode moves one half, the other, or both. Named here so the
        // branches below read as intent rather than as string comparisons
        // repeated five times.
        $pushesFiles = in_array($mode, ['files', 'full'], true);
        $pushesDatabase = in_array($mode, ['database', 'full'], true);

        $rollbackDirectory = $target->panelPath().'/staging-rollbacks/'.Str::uuid();
        $fileSnapshot = $rollbackDirectory.'/files';
        $databaseSnapshot = null;

        // No file snapshot for a database-only push. Nothing writes to the
        // document root in that mode, so copying the whole of it would be a
        // slow way to protect files that cannot change.
        if ($pushesFiles) {
            try {
                $this->snapshotFiles($target, $fileSnapshot);
            } catch (Throwable $failure) {
                $this->discardFileSnapshot($target, $rollbackDirectory);

                throw $failure;
            }
        }

        // Any mode that replaces the database dumps it first — that is the
        // half carrying orders and customers, and it is the half that cannot
        // restore itself. A files-only push does not touch it: the post-push
        // work it runs writes nothing but transients and `rewrite_rules`,
        // which WordPress regenerates on demand, so dumping a large database
        // there would be real cost to protect data that comes back anyway.
        // Unless the user turned the copy off (2026-10-29): then a database
        // that fails part-way is not put back. Files still are — their
        // snapshot is only kept while the swap runs, never as a copy.
        if ($pushesDatabase && $safetyCopy) {
            try {
                $databaseSnapshot = $this->safetyDump($target);
            } catch (Throwable $failure) {
                $this->discardFileSnapshot($target, $rollbackDirectory);

                throw $failure;
            }
        }

        // A visitor mid-checkout must never see the site half-swapped —
        // the same "unavailable" page enable/disable already trusts, reused
        // rather than a second maintenance-mode mechanism invented here.
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
                // Only restore halves that were snapshotted. A database-only
                // push never took a file snapshot, and `restoreFiles` against
                // a directory that was never written would replace the live
                // document root with nothing.
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
        // The list comes from the strategy: which files carry a site's own
        // identity is a question only the site type can answer, and when this
        // manager tried to answer it the WordPress ones were missing.
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

        // Before the chown below, deliberately: these passes write files as
        // root, and a pushed image owned by root is one the site user cannot
        // replace or delete from the Media Library afterwards.
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
     * No `--delete`, which is the whole point — this runs for the paths that
     * must cross without a push being able to erase what is already on the
     * other side.
     *
     * The source is probed first because a site that has never had an upload
     * has no `wp-content/uploads` at all, and rsync exits non-zero on a
     * missing source — which would fail the push of a site whose only fault is
     * that nobody has added a photo yet. `probe()` rather than a bare `run()`
     * so that "the directory is not there" stays distinct from "the panel was
     * not allowed to ask": a sudo refusal must fail the push, not be read as
     * an answer and silently skip the media.
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

        // mkdir -p, because rsync will create the leaf but not the path above
        // it — `wp-content` exists on any WordPress site, but this contract is
        // not WordPress-only and a nested merge path on a fresh destination
        // would otherwise fail.
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

        // Room for it first (2026-10-29): the snapshot is a whole copy of the
        // site's files on this server's disk for as long as the push runs,
        // and a full disk takes every site down with it. Measured now with
        // `du`, which still prints a usable total when it cannot read a few
        // entries; no total at all is let through, as for backups.
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
     * A local DB dump kept on the site's own disk before a full push
     * overwrites production's database — not a substitute for the full
     * Backups feature (which needs a configured storage destination that
     * may not exist), just a same-box safety net for the one irreversible
     * part of a push. Kept, not pruned — a handful of SQL files is cheap,
     * and this is the file someone reaches for the day a push goes wrong.
     *
     * @return array{database: Database, path: string}|null
     */
    private function safetyDump(Application $production): ?array
    {
        $database = Database::where('application_id', $production->id)->first();

        if ($database === null) {
            return null;
        }

        // Above the document root: this is a full dump of the production
        // database, and inside the served directory the only thing between it
        // and the internet is a vhost deny rule.
        $directory = $production->panelPath().'/staging-backups';
        $path = $directory.'/pre-push-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.sql';

        $created = $this->serverOps->run(
            ['mkdir', '-p', $directory],
            ['feature' => 'application', 'op' => 'staging_safety_dir', 'application' => $production->id],
        );

        if ($created->failed()) {
            throw new StagingOperationException($created->reference, $created->busy, $created->staleLock);
        }

        // Room for it first (2026-10-29): the dump goes to this server's disk,
        // and a full disk takes every site down with it. Measured now rather
        // than read from the stored size, which can be days old. Unknown size
        // or an unmeasurable disk is let through, as for backups.
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
            // A dump that died part-way — out of space, most likely — is not a
            // copy anyone can restore from, and it is holding the space.
            $this->serverOps->run(
                ['rm', '-f', '--', $path],
                ['feature' => 'application', 'op' => 'staging_safety_dump_cleanup', 'application' => $production->id],
            );

            throw $e;
        }

        return ['database' => $database, 'path' => $path];
    }
}
