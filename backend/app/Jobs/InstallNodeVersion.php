<?php

namespace App\Jobs;

use App\Services\ActivityLogger;
use App\Services\Server\Runtimes\NodeRuntime;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Installing a Node version means downloading and unpacking a runtime, which
 * is far too slow to hold a request open for.
 *
 * Unique per version: a double-clicked button would otherwise start two
 * `fnm install` runs for the same version, racing each other over the same
 * directory. `$tries = 1` for the same reason as every other server mutation
 * — an automatic retry just repeats a failure the operator needs to read.
 */
class InstallNodeVersion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public string $version) {}

    /**
     * Two requests for Node 20 are one job; Node 20 and Node 22 are two.
     */
    public function uniqueId(): string
    {
        return 'node-install-'.$this->version;
    }

    public function handle(NodeRuntime $node, ActivityLogger $log): void
    {
        try {
            $node->install($this->version);
        } catch (Throwable $e) {
            $log->log('runtime.node_install_failed', null, ['version' => $this->version]);

            throw $e;
        }

        $log->log('runtime.node_installed', null, ['version' => $this->version]);
    }
}
