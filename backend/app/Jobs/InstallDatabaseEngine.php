<?php

namespace App\Jobs;

use App\Exceptions\Server\Database\EngineInstallException;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\Concerns\TracksActor;
use App\Services\ActivityLogger;
use App\Services\Runtime\DatabaseInstallProgress;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Databases\Installers\EngineInstallerManager;
use App\Services\Server\ServerOps;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Installs a database engine.
 *
 * Progress rides on `runtime_installs` with `runtime = 'database'`, like PHP and Node versions:
 * `ready` is never stored (a successful install deletes the row, so the box stays the truth),
 * failures carry a classified code rather than raw stderr, and the message is built in the
 * viewer's locale at read time.
 */
class InstallDatabaseEngine implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;
    use TracksActor;

    /**
     * One attempt: a blind retry of a half-finished apt run can leave dpkg needing
     * `dpkg --configure -a`; the user gets an explicit retry instead.
     */
    public int $tries = 1;

    /**
     * Covers the package download, post-install scripts, account provisioning and apt's wait on a
     * lock another install holds (usual on a fresh box). `retry_after` in config/queue.php must
     * stay above this; a test asserts it.
     */
    public int $timeout;

    public function __construct(public string $engine, public ?int $actorId = null)
    {
        $this->timeout = (int) config('server.databases.install_timeout', 900)
            + ServerOps::aptLockBudgetSeconds()
            + 120;
    }

    /**
     * One install per engine in flight: two `apt-get install` runs at once fight over the dpkg
     * lock and one fails for no user-visible reason.
     */
    public function uniqueId(): string
    {
        return 'database-engine-'.$this->engine;
    }

    public function handle(
        EngineInstallerManager $installers,
        InstallTracker $installs,
        ActivityLogger $log,
        ServerCapabilities $capabilities,
    ): void {
        $row = $installs->begin('database', $this->engine);
        $progress = $row === null ? null : new DatabaseInstallProgress($row);

        try {
            $installers->installer($this->engine)->install(
                $progress === null ? null : fn (string $step) => $progress->step($step),
                $progress === null ? null : fn (string $chunk) => $progress->output($chunk),
                // Recorded at request time, not asked again here: on a retry the box has already
                // changed. See MongoDbInstaller::install().
                $row?->was_absent,
            );
        } catch (EngineInstallException $e) {
            $progress?->flushOutput();
            $installs->fail('database', $this->engine, null, $e->reason, $e->reference);
            $log->log('database.engine_install_failed', null, [
                'engine' => $this->engine,
                'reason' => $e->reason,
            ], actor: $this->actor());

            throw $e;
        } catch (Throwable $e) {
            $progress?->flushOutput();
            $installs->fail('database', $this->engine, null, 'unknown');
            $log->log('database.engine_install_failed', null, [
                'engine' => $this->engine,
                'reason' => 'unknown',
            ], actor: $this->actor());

            throw $e;
        }

        // The engine is on disk now, so the row has nothing left to say.
        $installs->succeed('database', $this->engine);

        // So the new engine is usable immediately, not after the next detection.
        $capabilities->refresh();

        $log->log('database.engine_installed', null, ['engine' => $this->engine], actor: $this->actor());
    }

    /**
     * The job died outright (timeout, worker killed). Without this the row stays `installing`
     * forever and the setup page spins.
     */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandon('database', $this->engine, why: $e);
    }
}
