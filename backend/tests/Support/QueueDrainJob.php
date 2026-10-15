<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\Process\Process;

/** Unprivileged portable signal probe; never used by the panel. */
class QueueDrainJob implements ShouldQueue
{
    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public string $directory, public string $name, public string $mode) {}

    public function handle(): void
    {
        file_put_contents($this->directory.'/accepted-'.$this->name, '1', FILE_APPEND);

        if ($this->name === 'first') {
            (new Process([PHP_BINARY, __DIR__.'/../Fixtures/queue-drain/child.php', $this->directory, $this->mode]))
                ->setTimeout(15)->mustRun();
        }

        file_put_contents($this->directory.'/completed-'.$this->name, '1', FILE_APPEND);
    }
}
