<?php

namespace App\Services\Server\Applications;

use App\Contracts\StagingStrategy;
use App\Exceptions\Server\Application\StagingOperationException;
use App\Models\Application;
use App\Models\Database;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\ServerOps;
use Illuminate\Support\Str;

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

        $staging = Application::create([
            'system_user_id' => $production->system_user_id,
            'production_application_id' => $production->id,
            'name' => "{$production->name} (Staging)",
            'domain' => $domain,
            'site_type' => $production->site_type,
            'serving_profile' => $production->serving_profile,
            'php_version' => $production->php_version,
            'web_root' => $production->web_root,
            'status' => 'pending',
        ]);

        $staging->load('systemUser');

        $this->provisioner->provision($staging);

        $this->rsync(
            $this->provisioner->documentRoot($production->load('systemUser')),
            $this->provisioner->documentRoot($staging),
            $staging,
        );

        $strategy->create($production, $staging);

        $staging->status = 'active';
        $staging->save();

        return $staging->fresh();
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

        $directory = $this->provisioner->documentRoot($production).'/.panel/staging-backups';
        $path = $directory.'/pre-push-'.now()->format('Ymd-His').'.sql';

        $this->serverOps->run(['mkdir', '-p', $directory], ['feature' => 'application', 'op' => 'staging_safety_dir', 'application' => $production->id]);

        $this->databases->engine($database->engine)->dump($database->name, $path);
    }
}
