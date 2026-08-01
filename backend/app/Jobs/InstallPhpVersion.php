<?php

namespace App\Jobs;

use App\Exceptions\Server\Runtime\RuntimeInstallException;
use App\Jobs\Concerns\TracksActor;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Runtimes\PhpRuntime;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * apt install of a PHP version and its base extensions — minutes, not
 * milliseconds, so it does not hold a request open.
 *
 * Unique per version, because apt takes a lock: a second run for the same
 * version would sit waiting for the first and then repeat its work.
 */
class InstallPhpVersion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use TracksActor;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $version, public ?int $actorId = null) {}

    public function uniqueId(): string
    {
        return 'php-install-'.$this->version;
    }

    public function handle(PhpRuntime $php, ActivityLogger $log, InstallTracker $installs): void
    {
        try {
            $php->install($this->version);
        } catch (RuntimeInstallException $e) {
            $installs->fail('php', $this->version, null, $e->reason, $e->reference);
            $log->log('php.install_failed', null, ['version' => $this->version, 'reason' => $e->reason], actor: $this->actor());

            throw $e;
        } catch (Throwable $e) {
            $installs->fail('php', $this->version, null, 'unknown');
            $log->log('php.install_failed', null, ['version' => $this->version], actor: $this->actor());

            throw $e;
        }

        // The version is on disk now, so the row has nothing left to say.
        $installs->succeed('php', $this->version);
        $log->log('php.installed', null, ['version' => $this->version], actor: $this->actor());
    }

    /**
     * The job died outright — timeout, or the worker was killed. Without this
     * the row sits at `installing` forever and the screen spins on something
     * that stopped running.
     */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandon('php', $this->version);
    }
}
