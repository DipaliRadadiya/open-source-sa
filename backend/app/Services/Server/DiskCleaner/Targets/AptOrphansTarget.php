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

    public function paths(): array
    {
        return ['apt: unused packages & old kernels'];
    }

    public function estimate(): int
    {
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
        return $this->serverOps->run(
            ['apt-get', '-y', 'autoremove', '--purge'],
            ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()],
            300,
        );
    }
}
