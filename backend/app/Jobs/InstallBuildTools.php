<?php

namespace App\Jobs;

use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\Concerns\TracksActor;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallFailureClassifier;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\BuildTools\BuildToolsManager;
use App\Services\Server\ServerOps;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Installs the compiler toolchain, so npm can build a package that ships no prebuilt binary for
 * the Node version a site runs.
 *
 * Queued because apt is too slow for a request, and `$tries = 1` because retrying a package
 * install just repeats a failure someone needs to read.
 *
 * `ShouldBeUnique` because apt takes a lock: a second run waits on `configure_apt_lock_wait` and
 * then fails for a reason unrelated to the user's server.
 */
class InstallBuildTools implements ShouldBeUnique, ShouldQueue
{
    // Gives the unique lock an expiry over this job's timeout. Without it a worker killed mid-apt
    // leaves a lock nothing releases, and every later install request is silently dropped.
    use ExpiresUniqueLock;
    use Queueable;
    use TracksActor;

    /** apt decides the version, so the tracker's key is constant, as in {@see InstallFail2ban}. */
    public const RUNTIME = 'build_tools';

    public const VERSION = 'latest';

    /**
     * The metapackage, not the individual compilers: naming gcc and make alone would leave out the
     * headers node-gyp needs and fail later, in the build.
     */
    public const PACKAGE = 'build-essential';

    public int $tries = 1;

    /**
     * The work itself, plus apt's wait for a lock another package manager holds
     * ({@see ServerOps::aptLockBudgetSeconds()}), as InstallDatabaseEngine does. Ubuntu's
     * first-boot updates may still hold it, and the worker must not kill the job mid-wait.
     */
    public int $timeout;

    public function __construct(public ?int $actorId = null)
    {
        $this->timeout = 900 + ServerOps::aptLockBudgetSeconds();
    }

    public function handle(
        ServerOps $serverOps,
        BuildToolsManager $buildTools,
        ActivityLogger $log,
        InstallTracker $installs,
        InstallFailureClassifier $classifier,
    ): void {
        $installs->begin(self::RUNTIME, self::VERSION);

        $result = $serverOps->apt(
            ['apt-get', 'install', '-y', self::PACKAGE],
            ['feature' => 'build_tools', 'op' => 'install'],
            timeout: 900,
        );

        if ($result->failed()) {
            // Recorded, not just logged: `installed` is derived from the binaries, so a failed
            // install would otherwise look like one still running.
            $installs->fail(
                self::RUNTIME,
                self::VERSION,
                null,
                $classifier->classify(self::RUNTIME, $result),
                $result->reference,
            );

            $log->log('build_tools.install_failed', null, ['reference' => $result->reference], actor: $this->actor());

            return;
        }

        // apt succeeding is not the same as being able to compile: a binary can still be off PATH,
        // so check before reporting success.
        if (! $buildTools->installed()) {
            $installs->fail(self::RUNTIME, self::VERSION, null, 'incomplete', $result->reference);

            $log->log('build_tools.install_failed', null, [
                'reference' => $result->reference,
                'missing' => implode(', ', $buildTools->missing()),
            ], actor: $this->actor());

            return;
        }

        // Present on disk now, which `installed()` already reads: the row has nothing left to say.
        $installs->succeed(self::RUNTIME, self::VERSION);

        $log->log('build_tools.installed', null, [], actor: $this->actor());
    }

    /**
     * The job died outright (apt timeout, or the worker was killed). Without this the row stays at
     * `installing` and the screen spins forever.
     */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandon(self::RUNTIME, self::VERSION, why: $e);
    }
}
