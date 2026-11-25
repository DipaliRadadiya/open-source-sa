<?php

namespace App\Jobs;

use App\Exceptions\Server\Php\PhpConfigException;
use App\Jobs\Concerns\TracksActor;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Php\IonCubeLoader;
use App\Services\Server\ServerOps;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Installs the ionCube Loader for one PHP version.
 *
 * Queued because the archive is ~29 MB and a request held open that long dies half done.
 *
 * `$tries = 1`, like InstallFail2ban: a retry repeats a failure to read, and this install ends
 * by writing a `zend_extension` line, which must not be retried unattended.
 */
class InstallIonCubeLoader implements ShouldQueue
{
    use Queueable;
    use TracksActor;

    /**
     * The tracker keys installs by (runtime, version). The runtime is the loader, not `php`, so
     * it is not confused with the PHP version itself being installed.
     */
    public const RUNTIME = 'ioncube';

    public int $tries = 1;

    /**
     * The work itself, plus apt's wait for a lock another package manager holds
     * ({@see ServerOps::aptLockBudgetSeconds()}). Must not be killed mid-wait, or it reads as
     * "stopped unexpectedly" instead of "another install is running".
     */
    public int $timeout;

    /**
     * `$keepCopy`: whether the files the install replaces get a recovery copy on disk, or are
     * held in memory only. Defaulted, so a job queued before the field existed still runs.
     */
    public function __construct(public string $version, public ?int $actorId = null, public bool $keepCopy = true)
    {
        $this->timeout = 600 + ServerOps::aptLockBudgetSeconds();
    }

    public function handle(IonCubeLoader $loader, ActivityLogger $log, InstallTracker $installs): void
    {
        $installs->begin(self::RUNTIME, $this->version);

        try {
            $loader->install($this->version, $this->keepCopy);
        } catch (PhpConfigException $e) {
            // Recorded, not just logged: `installed` is read from disk, so a failed install looks
            // like a running one. Uses the exception's own reason, not a generic `install_failed`.
            $installs->fail(self::RUNTIME, $this->version, null, $e->reason(), $e->reference);

            $log->log('php.ioncube_install_failed', null, [
                'version' => $this->version,
                'reference' => $e->reference,
            ], actor: $this->actor());

            return;
        }

        $installs->succeed(self::RUNTIME, $this->version);

        $log->log('php.ioncube_installed', null, ['version' => $this->version, 'safety_copy' => $this->keepCopy], actor: $this->actor());
    }

    /**
     * The job died outright (timeout or killed worker); otherwise the row stays at `installing`.
     */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandon(self::RUNTIME, $this->version, why: $e);
    }
}
