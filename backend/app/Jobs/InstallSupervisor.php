<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksActor;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallFailureClassifier;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Applications\WorkerSupervisor;
use App\Services\Server\ServerOps;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Installs supervisord, which workers need and an upgraded panel has not got.
 *
 * Workers became supervisor programs on 2026-09-07 and `install.sh` gained the
 * package in the same commit — which reaches new installs only, because the
 * updater ships code and never packages. Until now the panel told the person
 * to go and run apt themselves, which is a fair instruction and a poor answer:
 * the panel has the grant, knows the package name, and installs PHP versions,
 * database engines and fail2ban the same way already.
 *
 * Queued, because apt is far too slow to hold a request open for — the lock
 * alone is waited out for up to ten minutes. `$tries = 1` for the same reason
 * as every other server install job: retrying a package install automatically
 * just repeats a failure somebody needs to read.
 */
class InstallSupervisor implements ShouldQueue
{
    use Queueable;
    use TracksActor;

    /**
     * There is one supervisor and apt decides its version, so the tracker's
     * (runtime, version) key needs a constant rather than a choice. `latest`
     * is what apt actually installs, so it is also true. Same shape as
     * {@see InstallFail2ban}.
     */
    public const RUNTIME = 'supervisor';

    public const VERSION = 'latest';

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public ?int $actorId = null) {}

    public function handle(
        ServerOps $serverOps,
        WorkerSupervisor $supervisor,
        ActivityLogger $log,
        InstallTracker $installs,
        InstallFailureClassifier $classifier,
    ): void {
        $result = $serverOps->apt(
            ['apt-get', 'install', '-y', '--no-install-recommends', 'supervisor'],
            ['feature' => 'application', 'op' => 'supervisor_install'],
            timeout: $this->timeout,
        );

        // Recorded, not merely logged. The screen reads "is supervisor there",
        // a boolean derived from the binary existing — so a failed install and
        // one still running look identical for the ten minutes apt is allowed,
        // and then forever. Same reasoning as the fail2ban job.
        if ($result->failed()) {
            $installs->fail(
                self::RUNTIME,
                self::VERSION,
                null,
                $classifier->classify(self::RUNTIME, $result->output().$result->errorOutput()),
                $result->reference,
            );

            $log->log('application.supervisor_install_failed', null, [
                'reference' => $result->reference,
            ], actor: $this->actor());

            return;
        }

        // Asked rather than assumed. apt exiting 0 is not the same claim as
        // "supervisorctl is on PATH", and the next worker create asks exactly
        // this question — so the install reports success only if it can give
        // the same answer.
        if (! $supervisor->installed()) {
            $installs->fail(
                self::RUNTIME,
                self::VERSION,
                null,
                'not_installed',
                $result->reference,
            );

            $log->log('application.supervisor_install_failed', null, [
                'reference' => $result->reference,
            ], actor: $this->actor());

            return;
        }

        $installs->succeed(self::RUNTIME, self::VERSION);

        $log->log('application.supervisor_installed', null, [], actor: $this->actor());
    }

    /**
     * The job died outright — apt hit the timeout, or the worker was killed.
     * Without this the row sits at `installing` and the screen spins on
     * something that stopped running.
     */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandon(self::RUNTIME, self::VERSION);
    }
}
