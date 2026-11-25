<?php

namespace App\Jobs;

use App\Exceptions\Server\Runtime\RuntimeInstallException;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\Concerns\TracksActor;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallProgress;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Php\IonCubeLoader;
use App\Services\Server\Runtimes\PhpRuntime;
use App\Services\Server\ServerOps;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * apt install of a PHP version and its base extensions; queued because it takes minutes.
 *
 * Unique per version: apt takes a lock, so a second run would wait and then repeat the work.
 */
class InstallPhpVersion implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;
    use TracksActor;

    public int $tries = 1;

    /**
     * The install plus apt's wait for a lock another package manager holds
     * ({@see ServerOps::aptLockBudgetSeconds()}); the worker must not kill the job mid-wait.
     */
    public int $timeout;

    public function __construct(public string $version, public ?int $actorId = null)
    {
        $this->timeout = 900 + ServerOps::aptLockBudgetSeconds();
    }

    public function uniqueId(): string
    {
        return 'php-install-'.$this->version;
    }

    public function handle(PhpRuntime $php, ActivityLogger $log, InstallTracker $installs, ServerCapabilities $capabilities): void
    {
        $row = $installs->begin('php', $this->version);
        $progress = $row ? new InstallProgress($row) : null;

        try {
            $php->install($this->version, $progress === null ? null : function (string $chunk) use ($progress) {
                // Persisted on a step change only (apt emits hundreds of chunks); the tail stays
                // in memory and is flushed on failure.
                if ($progress->push($chunk)) {
                    $progress->persist();
                }
            });
        } catch (RuntimeInstallException $e) {
            $progress?->persist();
            $installs->fail('php', $this->version, null, $e->reason, $e->reference);
            $log->log('php.install_failed', null, ['version' => $this->version, 'reason' => $e->reason], actor: $this->actor());

            throw $e;
        } catch (Throwable $e) {
            $progress?->persist();
            $installs->fail('php', $this->version, null, 'unknown');
            $log->log('php.install_failed', null, ['version' => $this->version], actor: $this->actor());

            throw $e;
        }

        // The version is on disk now, so the row has nothing left to say.
        $installs->succeed('php', $this->version);
        // Re-detect capabilities: the row keeps what install.sh wrote until refreshed. Same as
        // InstallDatabaseEngine.
        $capabilities->refresh();
        $log->log('php.installed', null, ['version' => $this->version], actor: $this->actor());

        $this->followIonCube($php, $log, $installs);
    }

    /**
     * When other PHP versions here have ionCube, the new one gets it too (moving a site with
     * encoded files would break otherwise). Queued as its own tracked install; never fatal.
     */
    private function followIonCube(PhpRuntime $php, ActivityLogger $log, InstallTracker $installs): void
    {
        try {
            $loader = app(IonCubeLoader::class);
            $others = array_column($php->versions(), 'version');

            if (! $loader->shouldFollow($this->version, $others)) {
                return;
            }

            $installs->start(InstallIonCubeLoader::RUNTIME, $this->version);
            InstallIonCubeLoader::dispatch($this->version, $this->actorId);
            $log->log('php.ioncube_install_followed', null, ['version' => $this->version], actor: $this->actor());
        } catch (Throwable $e) {
            Log::warning('ionCube could not be queued for a new PHP version', [
                'version' => $this->version,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The job died outright (timeout, worker killed); without this the row stays `installing`.
     */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandon('php', $this->version, why: $e);
    }
}
