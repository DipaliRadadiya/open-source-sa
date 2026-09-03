<?php

namespace App\Services\Server\Applications;

use App\Contracts\StagingStrategy;
use App\Exceptions\Server\Application\StagingOperationException;
use App\Exceptions\Server\Application\StagingRollbackException;
use App\Exceptions\Server\ServerOperationException;
use App\Models\Application;
use App\Models\Database;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
            'slug' => Application::uniqueSlug($name),
            'domain' => $domain,
            'site_type' => $production->site_type,
            'serving_profile' => $production->serving_profile,
            'php_version' => $production->php_version,
            'web_root' => $production->web_root,
            'status' => 'pending',
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
    public function push(Application $production, string $mode): void
    {
        $staging = $production->staging;

        if ($staging === null) {
            throw new StagingOperationException((string) Str::uuid());
        }

        $strategy = $this->strategyFor($production);

        if ($strategy === null) {
            throw new StagingOperationException((string) Str::uuid());
        }

        $production->load('systemUser');
        $staging->load('systemUser');

        $rollbackDirectory = $production->panelPath().'/staging-rollbacks/'.Str::uuid();
        $fileSnapshot = $rollbackDirectory.'/files';
        $databaseSnapshot = null;

        try {
            $this->snapshotFiles($production, $fileSnapshot);
        } catch (Throwable $failure) {
            $this->discardFileSnapshot($production, $rollbackDirectory);

            throw $failure;
        }

        // Only `full` dumps. A files-only push does not touch the database:
        // the post-push work it does run — flushing caches and rewrite rules
        // — writes nothing but transients and `rewrite_rules`, both of which
        // WordPress regenerates on demand. Dumping a large production
        // database on every files push would be real cost to protect data
        // that restores itself.
        if ($mode === 'full') {
            try {
                $databaseSnapshot = $this->safetyDump($production);
            } catch (Throwable $failure) {
                $this->discardFileSnapshot($production, $rollbackDirectory);

                throw $failure;
            }
        }

        // A visitor mid-checkout must never see the site half-swapped —
        // the same "unavailable" page enable/disable already trusts, reused
        // rather than a second maintenance-mode mechanism invented here.
        try {
            $this->provisioner->disable($production);
        } catch (Throwable $failure) {
            $this->discardFileSnapshot($production, $rollbackDirectory);

            throw $failure;
        }

        try {
            $this->rsync(
                $this->provisioner->documentRoot($staging),
                $this->provisioner->documentRoot($production),
                $production,
                $strategy->syncExcludes(),
            );

            $strategy->push($production, $staging, $mode);
            $this->provisioner->enable($production);
        } catch (Throwable $pushFailure) {
            try {
                $this->restoreFiles($production, $fileSnapshot);

                if ($databaseSnapshot !== null) {
                    $database = $databaseSnapshot['database'];
                    $this->databases->engine($database->engine)->restore($database->name, $databaseSnapshot['path']);
                }

                $production->refresh();

                if ($production->disabled_at !== null) {
                    $this->provisioner->enable($production);
                }

                $this->discardFileSnapshot($production, $rollbackDirectory);
            } catch (Throwable $rollbackFailure) {
                $reference = $this->failureReference($rollbackFailure);

                Log::channel('server-ops')->critical('staging push rollback failed', [
                    'feature' => 'application',
                    'op' => 'staging_push_rollback',
                    'application' => $production->id,
                    'reference' => $reference,
                    'push_reference' => $this->failureReference($pushFailure),
                    'rollback_reference' => $reference,
                    'rollback_directory' => $rollbackDirectory,
                ]);

                throw new StagingRollbackException($reference);
            }

            throw $pushFailure;
        }

        $this->discardFileSnapshot($production, $rollbackDirectory);
    }

    private function strategyFor(Application $production): ?StagingStrategy
    {
        return $this->siteTypes->find($production->site_type)?->stagingStrategy();
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function rsync(string $source, string $destination, Application $owner, array $patterns): void
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

        $ownership = $this->serverOps->run(
            ['chown', '-R', "{$owner->systemUser->username}:{$owner->systemUser->username}", $destination],
            ['feature' => 'application', 'op' => 'staging_rsync_chown', 'application' => $owner->id],
        );

        if ($ownership->failed()) {
            throw new StagingOperationException($ownership->reference, $ownership->busy, $ownership->staleLock);
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

        $snapshot = $this->serverOps->run(
            ['rsync', '-a', '--delete', rtrim($this->provisioner->documentRoot($production), '/').'/', rtrim($destination, '/').'/'],
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

        $this->databases->engine($database->engine)->dump($database->name, $path);

        return ['database' => $database, 'path' => $path];
    }
}
