<?php

namespace App\Jobs;

use App\Exceptions\Server\Setting\SettingOperationException;
use App\Jobs\Concerns\ExpiresUniqueLock;
use App\Jobs\Concerns\TracksActor;
use App\Services\ActivityLogger;
use App\Services\Runtime\InstallProgress;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Runtimes\PhpRuntime;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * `apt purge` of a PHP version — minutes, not milliseconds.
 *
 * Queued for the same reason installing is, which it was not before: removal
 * ran inside the HTTP request, and nginx cuts a request off at
 * fastcgi_read_timeout. The browser got a timeout while apt carried on, so the
 * screen never refreshed, the version disappeared a minute later on its own,
 * and pressing Remove again answered 404 for a version that had already gone.
 *
 * Unique per version, because apt takes a lock: a second purge would sit
 * waiting on the first and then repeat its work.
 */
class RemovePhpVersion implements ShouldBeUnique, ShouldQueue
{
    use ExpiresUniqueLock;
    use Queueable;
    use TracksActor;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $version, public ?int $actorId = null) {}

    public function uniqueId(): string
    {
        return 'php-remove-'.$this->version;
    }

    public function handle(PhpRuntime $php, ActivityLogger $log, InstallTracker $installs): void
    {
        $row = $installs->current('php', $this->version);
        $progress = $row ? new InstallProgress($row) : null;

        try {
            $php->uninstall($this->version, $progress === null ? null : function (string $chunk) use ($progress) {
                if ($progress->push($chunk)) {
                    $progress->persist();
                }
            });
        } catch (SettingOperationException $e) {
            // Flushed first: apt's last words are the only thing that explains
            // a purge that would not complete. Keep its reference too: the
            // API never exposes apt output, so this is the support trail.
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

        // The version is off the disk now, so the row has nothing left to say
        // — the same rule an install finishing follows.
        $installs->succeed('php', $this->version);
        $log->log('php.uninstalled', null, ['version' => $this->version], actor: $this->actor());
    }

    /**
     * Timeout, or the worker was killed. Without this the row sits at
     * `removing` forever and the card spins on something that stopped running.
     */
    public function failed(?Throwable $e): void
    {
        app(InstallTracker::class)->abandonRemoval('php', $this->version);
    }
}
