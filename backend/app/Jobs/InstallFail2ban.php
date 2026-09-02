<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksActor;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallFailureClassifier;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Fail2ban\Fail2banManager;
use App\Services\Server\ServerOps;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Installs fail2ban. Queued because apt is far too slow to hold a request
 * open for, and `$tries = 1` for the same reason as the other server jobs:
 * an automatic retry of a package install just repeats a failure the operator
 * needs to read.
 *
 * Nothing is enabled here. A freshly installed fail2ban that immediately
 * started banning would be a surprise, and the one jail worth having is the
 * one that can lock you out — that stays a deliberate click.
 */
class InstallFail2ban implements ShouldQueue
{
    use Queueable;
    use TracksActor;

    /**
     * There is one fail2ban and apt decides its version, so the tracker's
     * (runtime, version) key needs a constant rather than a choice. `latest`
     * is what apt actually installs, so it is also true.
     */
    public const RUNTIME = 'fail2ban';

    public const VERSION = 'latest';

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public ?int $actorId = null) {}

    public function handle(
        ServerOps $serverOps,
        Fail2banManager $fail2ban,
        ActivityLogger $log,
        InstallTracker $installs,
        InstallFailureClassifier $classifier,
    ): void {
        $result = $serverOps->apt(
            ['apt-get', 'install', '-y', 'fail2ban'],
            ['feature' => 'fail2ban', 'op' => 'install'],
            timeout: 600,
        );

        if ($result->failed()) {
            // Recorded, not just logged. This used to `return` after an
            // activity-log line, and the screen reports only `installed`
            // — a boolean derived from the package being present. So a failed
            // install was indistinguishable from one still running, for the
            // ten minutes apt is allowed and then forever.
            $installs->fail(
                self::RUNTIME,
                self::VERSION,
                null,
                $classifier->classify(self::RUNTIME, $result->output().$result->errorOutput()),
                $result->reference,
            );

            $log->log('fail2ban.install_failed', null, ['reference' => $result->reference], actor: $this->actor());

            return;
        }

        // Write our drop-in with every jail off, so the installed service has
        // the panel's settings from the start and no jail nobody asked for.
        $fail2ban->write(
            (array) config('server.fail2ban.defaults'),
            [],
            array_fill_keys(array_column((array) config('server.fail2ban.jails', []), 'name'), false),
        );

        // Installed and configured: the package is on disk now, which is the
        // answer `GET /fail2ban` already reads, so the row has nothing left
        // to say.
        $installs->succeed(self::RUNTIME, self::VERSION);

        $log->log('fail2ban.installed', null, ['version' => $fail2ban->version() ?? '—'], actor: $this->actor());
    }

    /**
     * The job died outright — apt hit the 600s timeout, or the worker was
     * killed. Without this the row sits at `installing` and the screen spins
     * on something that stopped running.
     */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandon(self::RUNTIME, self::VERSION);
    }
}
