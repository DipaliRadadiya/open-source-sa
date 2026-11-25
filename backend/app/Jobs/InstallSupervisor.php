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
 * Installs supervisord, which workers need and a panel installed before workers existed lacks
 * (the updater ships code, never packages).
 *
 * Queued because apt is too slow for a request (the lock alone is waited out for up to ten
 * minutes). `$tries = 1` like every server install job: a retry just repeats a failure to read.
 */
class InstallSupervisor implements ShouldQueue
{
    use Queueable;
    use TracksActor;

    /**
     * The tracker's (runtime, version) key needs a constant: apt picks the version, so `latest`.
     * Same shape as {@see InstallFail2ban}.
     */
    public const RUNTIME = 'supervisor';

    public const VERSION = 'latest';

    public int $tries = 1;

    /**
     * The work itself, plus apt's wait for a lock another package manager holds
     * ({@see ServerOps::aptLockBudgetSeconds()}). Must not be killed mid-wait, or it reads as
     * "stopped unexpectedly" instead of "another install is running".
     */
    public int $timeout;

    public function __construct(public ?int $actorId = null)
    {
        $this->timeout = 600 + ServerOps::aptLockBudgetSeconds();
    }

    public function handle(
        ServerOps $serverOps,
        WorkerSupervisor $supervisor,
        ActivityLogger $log,
        InstallTracker $installs,
        InstallFailureClassifier $classifier,
    ): void {
        $installs->begin(self::RUNTIME, self::VERSION);

        $result = $serverOps->apt(
            ['apt-get', 'install', '-y', '--no-install-recommends', 'supervisor'],
            ['feature' => 'application', 'op' => 'supervisor_install'],
            timeout: 600,
        );

        // Recorded, not merely logged: the screen only sees whether the binary exists, so a failed
        // install would look like a running one.
        if ($result->failed()) {
            $installs->fail(
                self::RUNTIME,
                self::VERSION,
                null,
                $classifier->classify(self::RUNTIME, $result),
                $result->reference,
            );

            $log->log('application.supervisor_install_failed', null, [
                'reference' => $result->reference,
            ], actor: $this->actor());

            return;
        }

        // apt exiting 0 does not mean supervisorctl is on PATH, which is what worker create checks.
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
     * The job died outright (timeout or killed worker); otherwise the row stays at `installing`.
     */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandon(self::RUNTIME, self::VERSION, why: $e);
    }
}
