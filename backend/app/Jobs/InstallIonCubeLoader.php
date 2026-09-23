<?php

namespace App\Jobs;

use App\Exceptions\Server\Php\PhpConfigException;
use App\Jobs\Concerns\TracksActor;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Php\IonCubeLoader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Installs the ionCube Loader for one PHP version.
 *
 * Queued for the reason every other install here is: the archive is ~29 MB
 * and a request held open for that long dies at the web server with the work
 * half done.
 *
 * `$tries = 1`, matching InstallFail2ban: an automatic retry of a server
 * mutation just repeats a failure somebody needs to read — and this one ends
 * in writing a `zend_extension` line, which is not a thing to retry
 * unattended.
 */
class InstallIonCubeLoader implements ShouldQueue
{
    use Queueable;
    use TracksActor;

    /**
     * The tracker keys installs by (runtime, version). The runtime is the
     * loader rather than `php`, so an ionCube install in flight is not
     * confused with the PHP version itself being installed — they are
     * different operations on the same version and the screen shows both.
     */
    public const RUNTIME = 'ioncube';

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $version, public ?int $actorId = null) {}

    public function handle(IonCubeLoader $loader, ActivityLogger $log, InstallTracker $installs): void
    {
        try {
            $loader->install($this->version);
        } catch (PhpConfigException $e) {
            // Recorded, not just logged. `installed` is read from disk, so a
            // failed install is otherwise indistinguishable from one still
            // running — for ten minutes, and then forever.
            // The exception's own cause, not a generic `install_failed`: a
            // download that failed and a config test that failed are fixed in
            // different ways, and the card is the only place the user reads it.
            $installs->fail(self::RUNTIME, $this->version, null, $e->reason(), $e->reference);

            $log->log('php.ioncube_install_failed', null, [
                'version' => $this->version,
                'reference' => $e->reference,
            ], actor: $this->actor());

            return;
        }

        $installs->succeed(self::RUNTIME, $this->version);

        $log->log('php.ioncube_installed', null, ['version' => $this->version], actor: $this->actor());
    }

    /**
     * The job died outright — the download hit the timeout, or the worker was
     * killed. Without this the row sits at `installing` and the card spins on
     * something that stopped running.
     */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandon(self::RUNTIME, $this->version);
    }
}
