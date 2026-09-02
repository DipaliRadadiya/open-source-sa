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
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Installs a database engine.
 *
 * Progress rides on `runtime_installs` with `runtime = 'database'` — the same
 * table PHP and Node versions use. Its own migration says "any runtime added
 * later gets progress for free", and taking that up means inheriting the parts
 * that were hard to get right: `ready` is never stored (a successful install
 * deletes the row and the box becomes the truth again, so the two cannot drift),
 * failures carry a classified code rather than raw stderr, and the message is
 * built in the *viewer's* locale at read time.
 */
class InstallDatabaseEngine implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;
    use TracksActor;

    /**
     * One attempt. A blind retry of a half-finished apt run is how a package
     * database ends up needing `dpkg --configure -a` by hand; the user gets an
     * explicit retry instead.
     */
    public int $tries = 1;

    /**
     * Long enough for the package download plus its post-install scripts, with
     * room for the account provisioning after. `retry_after` in config/queue.php
     * has to stay above this — a test asserts it does.
     */
    public int $timeout;

    public function __construct(public string $engine, public ?int $actorId = null)
    {
        $this->timeout = (int) config('server.databases.install_timeout', 900) + 120;
    }

    /**
     * One install per engine in flight. Two `apt-get install` runs at once fight
     * over the dpkg lock and one of them fails for a reason that has nothing to
     * do with the user.
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
        $row = $installs->current('database', $this->engine);
        $progress = $row === null ? null : new DatabaseInstallProgress($row);

        try {
            $installers->installer($this->engine)->install(
                $progress === null ? null : fn (string $step) => $progress->step($step),
                $progress === null ? null : fn (string $chunk) => $progress->output($chunk),
                // Recorded when this install was requested, not asked again
                // here: on a retry the box has already changed underneath the
                // question. See MongoDbInstaller::install().
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

        // So the new engine is usable immediately rather than after the next
        // detection — creating a database is the first thing anyone will try.
        $capabilities->refresh();

        $log->log('database.engine_installed', null, ['engine' => $this->engine], actor: $this->actor());
    }

    /**
     * The job died outright — timeout, or the worker was killed. Without this the
     * row sits at `installing` forever and the setup page shows a spinner that
     * will never resolve.
     */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandon('database', $this->engine);
    }
}
