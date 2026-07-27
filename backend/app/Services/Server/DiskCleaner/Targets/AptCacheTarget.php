<?php

namespace App\Services\Server\DiskCleaner\Targets;

use App\Services\Server\DiskCleaner\AbstractCleanupTarget;
use App\Services\Server\ServerOpsResult;

/** Downloaded .deb package files in the apt cache (safe: `apt-get clean`). */
class AptCacheTarget extends AbstractCleanupTarget
{
    private const DIR = '/var/cache/apt/archives';

    public function key(): string
    {
        return 'apt_cache';
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
        return is_dir(self::DIR);
    }

    public function paths(): array
    {
        return [self::DIR];
    }

    public function estimate(): int
    {
        return $this->du(self::DIR);
    }

    public function clean(): ServerOpsResult
    {
        return $this->serverOps->run(
            ['apt-get', 'clean'],
            ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()],
            120,
        );
    }
}
