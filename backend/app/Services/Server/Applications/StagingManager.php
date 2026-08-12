<?php

namespace App\Services\Server\Applications;

use App\Contracts\StagingStrategy;
use App\Exceptions\Server\Application\StagingOperationException;
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
    /**
     * Excludes are sane defaults, not user-editable in v1 — the design this
     * follows explicitly says to ship the two simple modes first. Same
     * categories the Backups feature already excludes by convention: caches,
     * VCS metadata, build artifacts, the panel's own bookkeeping directory.
     *
     * @var array<int, string>
     */
    private const FILE_EXCLUDES = [
        // wp-content/uploads/ is intentionally excluded: rsync --delete means
        // pushing files mode would wipe production media added since staging was
        // cloned, and there is no safety copy for files-only mode. Media lives
        // in the database in WordPress; pushing the DB brings it back.
        'wp-content/uploads/',
        'wp-content/cache/', '.git/', 'node_modules/', '*.log', 'wp-content/upgrade/', '.panel/',
    ];

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
            $this->provisioner->provision($staging);

            $this->rsync(
                $this->provisioner->documentRoot($production->load('systemUser')),
                $this->provisioner->documentRoot($staging),
                $staging,
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

        if ($mode === 'full') {
            $this->safetyDump($production);
        }

        // A visitor mid-checkout must never see the site half-swapped —
        // the same "unavailable" page enable/disable already trusts, reused
        // rather than a second maintenance-mode mechanism invented here.
        $this->provisioner->disable($production);

        try {
            $this->rsync(
                $this->provisioner->documentRoot($staging),
                $this->provisioner->documentRoot($production),
                $production,
            );

            $strategy->push($production, $staging, $mode);
        } finally {
            // Re-enabled even if the push itself failed partway — a site
            // left showing "unavailable" forever because a push errored is
            // worse than one serving whatever state the rsync got to.
            $this->provisioner->enable($production);
        }
    }

    private function strategyFor(Application $production): ?StagingStrategy
    {
        return $this->siteTypes->find($production->site_type)?->stagingStrategy();
    }

    private function rsync(string $source, string $destination, Application $owner): void
    {
        $excludes = array_merge(
            ...array_map(fn (string $pattern) => ['--exclude', $pattern], self::FILE_EXCLUDES),
        );

        $result = $this->serverOps->run(
            array_merge(['rsync', '-a', '--delete'], $excludes, [rtrim($source, '/').'/', rtrim($destination, '/').'/']),
            ['feature' => 'application', 'op' => 'staging_rsync', 'application' => $owner->id],
            timeout: 300,
        );

        if ($result->failed()) {
            throw new StagingOperationException($result->reference);
        }

        $this->serverOps->run(
            ['chown', '-R', "{$owner->systemUser->username}:{$owner->systemUser->username}", $destination],
            ['feature' => 'application', 'op' => 'staging_rsync_chown', 'application' => $owner->id],
        );
    }

    /**
     * A local DB dump kept on the site's own disk before a full push
     * overwrites production's database — not a substitute for the full
     * Backups feature (which needs a configured storage destination that
     * may not exist), just a same-box safety net for the one irreversible
     * part of a push. Kept, not pruned — a handful of SQL files is cheap,
     * and this is the file someone reaches for the day a push goes wrong.
     */
    private function safetyDump(Application $production): void
    {
        $database = Database::where('application_id', $production->id)->first();

        if ($database === null) {
            return;
        }

        // Above the document root: this is a full dump of the production
        // database, and inside the served directory the only thing between it
        // and the internet is a vhost deny rule.
        $directory = $production->panelPath().'/staging-backups';
        $path = $directory.'/pre-push-'.now()->format('Ymd-His').'.sql';

        $this->serverOps->run(['mkdir', '-p', $directory], ['feature' => 'application', 'op' => 'staging_safety_dir', 'application' => $production->id]);

        $this->databases->engine($database->engine)->dump($database->name, $path);
    }
}
