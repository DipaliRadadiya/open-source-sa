<?php

namespace App\Services\Server\DiskCleaner\Targets;

use App\Services\Server\DiskCleaner\AbstractCleanupTarget;
use App\Services\Server\ServerOpsResult;

/** Auto-installed packages + old kernels no longer needed (`apt-get autoremove`). */
class AptOrphansTarget extends AbstractCleanupTarget
{
    public function key(): string
    {
        return 'apt_orphans';
    }

    public function group(): string
    {
        return 'package';
    }

    public function method(): string
    {
        return 'command';
    }

    public function available(): bool
    {
        return is_file('/usr/bin/apt-get');
    }

    /**
     * Not automatable. Everything else here removes files that can be
     * regenerated; this removes *packages*, on the strength of apt's
     * auto-installed flags — which are routinely wrong on a server that was
     * migrated, built by a provisioning script, or repaired with `apt -f`. And
     * `--purge` takes the configuration with them.
     *
     * That is a judgement worth making with somebody watching. The schedule
     * request and the scheduled command both already filter on this flag; no
     * target had ever set it, so the guard they were written around had never
     * had anything to guard.
     */
    public function safe(): bool
    {
        return false;
    }

    public function paths(): array
    {
        return ['apt: unused packages & old kernels'];
    }

    public function estimate(): int
    {
        // `run()`, not `apt()`, and deliberately: this is a simulation that
        // runs when the disk-cleaner screen is opened. apt()'s wait is sized
        // for a first-boot unattended-upgrades holding the lock for minutes,
        // and a page that hangs for minutes to put a number on a card is worse
        // than a card that says nothing. The clean itself waits; the preview
        // gives up and reports zero.
        $output = $this->serverOps->run(
            ['apt-get', '-s', 'autoremove'],
            ['feature' => 'disk_cleaner', 'op' => 'estimate', 'target' => $this->key()],
        )->output();

        // "After this operation, 123 MB disk space will be freed."
        if (preg_match('/After this operation, ([\d.,]+) ?([kKmMgG]?B) .*freed/', $output, $m)) {
            return $this->siBytes($m[1], $m[2]);
        }

        return 0;
    }

    public function clean(): ServerOpsResult
    {
        return $this->serverOps->apt(
            ['apt-get', '-y', 'autoremove', '--purge'],
            ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()],
            300,
        );
    }
}
