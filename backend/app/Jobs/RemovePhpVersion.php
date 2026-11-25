<?php

namespace App\Jobs;

use App\Exceptions\Server\Setting\SettingOperationException;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\Concerns\TracksActor;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallProgress;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Capabilities\ServerCapabilities;
use App\Services\Server\Runtimes\PhpRuntime;
use App\Services\Server\ServerOps;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * `apt purge` of a PHP version — minutes, not milliseconds.
 *
 * Queued, like installing: inside the HTTP request nginx would cut it off at fastcgi_read_timeout
 * while apt carried on.
 *
 * Unique per version, because apt takes a lock: a second purge would wait and repeat the work.
 */
class RemovePhpVersion implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;
    use TracksActor;

    public int $tries = 1;

    /**
     * The work itself, plus apt's wait for a lock another package manager holds
     * ({@see ServerOps::aptLockBudgetSeconds()}), as InstallDatabaseEngine does. The worker must not
     * kill the job mid-wait: that reads as "stopped unexpectedly", not "another install is running".
     */
    public int $timeout;

    public function __construct(public string $version, public ?int $actorId = null)
    {
        $this->timeout = 900 + ServerOps::aptLockBudgetSeconds();
    }

    public function uniqueId(): string
    {
        return 'php-remove-'.$this->version;
    }

    public function handle(PhpRuntime $php, ActivityLogger $log, InstallTracker $installs, ServerCapabilities $capabilities): void
    {
        $row = $installs->begin('php', $this->version);
        $progress = $row ? new InstallProgress($row) : null;

        try {
            $php->uninstall($this->version, $progress === null ? null : function (string $chunk) use ($progress) {
                if ($progress->push($chunk)) {
                    $progress->persist();
                }
            });
        } catch (SettingOperationException $e) {
            // Flushed first: apt's last output explains a failed purge. Keep the reference too:
            // the API never exposes apt output, so it is the support trail.
            $progress?->persist();
            $installs->fail('php', $this->version, null, 'remove_failed', $e->reference);
            $log->log('php.uninstall_failed', null, ['version' => $this->version], actor: $this->actor());

            throw $e;
        } catch (Throwable $e) {
            $progress?->persist();
            $installs->fail('php', $this->version, null, 'remove_failed');
            $log->log('php.uninstall_failed', null, ['version' => $this->version], actor: $this->actor());

            throw $e;
        }

        $installs->succeed('php', $this->version);

        // Mirror of the refresh after an install: without it, removing the last PHP version leaves
        // the recorded capability claiming PHP and the create screen offers apps that cannot run.
        $capabilities->refresh();

        $log->log('php.uninstalled', null, ['version' => $this->version], actor: $this->actor());
    }

    /**
     * Timeout, or the worker was killed. Without this the row sits at `removing` forever.
     */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandonRemoval('php', $this->version, why: $e);
    }
}
