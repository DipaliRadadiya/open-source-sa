<?php

namespace App\Jobs;

use App\Services\ActivityLogger;
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

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $version) {}

    public function uniqueId(): string
    {
        return 'php-install-'.$this->version;
    }

    public function handle(PhpRuntime $php, ActivityLogger $log): void
    {
        try {
            $php->install($this->version);
        } catch (Throwable $e) {
            $log->log('php.install_failed', null, ['version' => $this->version]);

            throw $e;
        }

        $log->log('php.installed', null, ['version' => $this->version]);
    }
}
