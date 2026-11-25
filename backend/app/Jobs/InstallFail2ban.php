<?php

namespace App\Jobs;

use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\Concerns\TracksActor;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallFailureClassifier;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Fail2ban\Fail2banManager;
use App\Services\Server\ServerOps;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Installs fail2ban. Queued because apt is too slow for a request; `$tries = 1` because a retry
 * of a package install only repeats a failure the operator needs to read.
 *
 * Only the SSH jail is enabled here; every other jail stays a deliberate click.
 */
class InstallFail2ban implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;
    use TracksActor;

    /**
     * apt decides fail2ban's version, so the tracker's (runtime, version) key uses a constant.
     */
    public const RUNTIME = 'fail2ban';

    public const VERSION = 'latest';

    public int $tries = 1;

    /**
     * The install plus apt's wait for a lock another package manager holds
     * ({@see ServerOps::aptLockBudgetSeconds()}); the worker must not kill the job mid-wait.
     */
    public int $timeout;

    public function __construct(public ?int $actorId = null)
    {
        $this->timeout = 600 + ServerOps::aptLockBudgetSeconds();
    }

    public function handle(
        ServerOps $serverOps,
        Fail2banManager $fail2ban,
        ActivityLogger $log,
        InstallTracker $installs,
        InstallFailureClassifier $classifier,
    ): void {
        $installs->begin(self::RUNTIME, self::VERSION);

        $result = $serverOps->apt(
            ['apt-get', 'install', '-y', 'fail2ban'],
            ['feature' => 'fail2ban', 'op' => 'install'],
            timeout: 600,
        );

        if ($result->failed()) {
            // Recorded, not just logged: the screen only knows `installed`, so a failed install
            // would look like one still running.
            $installs->fail(
                self::RUNTIME,
                self::VERSION,
                null,
                $classifier->classify(self::RUNTIME, $result),
                $result->reference,
            );

            $log->log('fail2ban.install_failed', null, ['reference' => $result->reference], actor: $this->actor());

            return;
        }

        // Write our drop-in so the service has the panel's settings from the start. The SSH jail
        // is on, every other jail off until asked for. It bans on the port SSH really uses
        // (jailOptions()); loopback is always ignored.
        $fail2ban->write(
            (array) config('server.fail2ban.defaults'),
            [],
            ['sshd' => true] + array_fill_keys(array_column((array) config('server.fail2ban.jails', []), 'name'), false),
        );

        // The package is on disk now, which is what `GET /fail2ban` reads.
        $installs->succeed(self::RUNTIME, self::VERSION);

        $log->log('fail2ban.installed', null, ['version' => $fail2ban->version() ?? '—'], actor: $this->actor());
    }

    /**
     * The job died outright (apt timeout, worker killed); without this the row stays `installing`.
     */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandon(self::RUNTIME, self::VERSION, why: $e);
    }
}
