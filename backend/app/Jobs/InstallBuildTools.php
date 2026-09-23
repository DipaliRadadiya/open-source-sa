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
 * Installs the compiler toolchain, so npm can build a package that ships no
 * prebuilt binary for the Node version a site runs.
 *
 * Queued because apt is far too slow to hold a request open for, and
 * `$tries = 1` for the same reason as every other server job: an automatic
 * retry of a package install just repeats a failure someone needs to read.
 *
 * `ShouldBeUnique` because apt takes a lock. Two of these at once is not a
 * theoretical race — the second waits on `configure_apt_lock_wait` and then
 * fails, and the user sees an install that broke for a reason that has nothing
 * to do with their server.
 */
class InstallBuildTools implements ShouldBeUnique, ShouldQueue
{
    // Gives the unique lock an expiry over this job's own timeout. Without it
    // a worker killed mid-apt leaves a lock nothing releases, and every later
    // install request is silently dropped — no error, no failed job, the
    // button simply stops working.
    use ExpiresUniqueLock;
    use Queueable;
    use TracksActor;

    /**
     * apt decides the version, so the tracker's (runtime, version) key needs a
     * constant rather than a choice — the same shape {@see InstallFail2ban}
     * uses, and `latest` is what apt actually installs.
     */
    public const RUNTIME = 'build_tools';

    public const VERSION = 'latest';

    /**
     * The metapackage, not the individual compilers.
     *
     * `installed()` asks whether the binaries are present, which is the real
     * question; installing asks apt for the standard set, which is the answer
     * a distribution already curates. Naming gcc and make individually would
     * leave out the headers node-gyp needs and fail later, in the build.
     */
    public const PACKAGE = 'build-essential';

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public ?int $actorId = null) {}

    public function handle(
        ServerOps $serverOps,
        BuildToolsManager $buildTools,
        ActivityLogger $log,
        InstallTracker $installs,
        InstallFailureClassifier $classifier,
    ): void {
        $result = $serverOps->apt(
            ['apt-get', 'install', '-y', self::PACKAGE],
            ['feature' => 'build_tools', 'op' => 'install'],
            timeout: 900,
        );

        if ($result->failed()) {
            // Recorded, not just logged. The screen reports `installed`, a
            // boolean derived from the binaries being present — so a failed
            // install is otherwise indistinguishable from one still running,
            // for the fifteen minutes apt is allowed and then forever.
            $installs->fail(
                self::RUNTIME,
                self::VERSION,
                null,
                $classifier->classify(self::RUNTIME, $result->output().$result->errorOutput()),
                $result->reference,
            );

            $log->log('build_tools.install_failed', null, ['reference' => $result->reference], actor: $this->actor());

            return;
        }

        // apt succeeding is not the same as the server being able to compile.
        // The packages can install and still leave a binary off PATH — and the
        // whole point of this job is that the next native build works, so that
        // is what gets checked before it reports success.
        if (! $buildTools->installed()) {
            $installs->fail(self::RUNTIME, self::VERSION, null, 'incomplete', $result->reference);

            $log->log('build_tools.install_failed', null, [
                'reference' => $result->reference,
                'missing' => implode(', ', $buildTools->missing()),
            ], actor: $this->actor());

            return;
        }

        // Present on disk now, which is the answer `installed()` already reads,
        // so the row has nothing left to say.
        $installs->succeed(self::RUNTIME, self::VERSION);

        $log->log('build_tools.installed', null, [], actor: $this->actor());
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
