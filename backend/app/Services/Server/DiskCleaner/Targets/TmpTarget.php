<?php

namespace App\Services\Server\DiskCleaner\Targets;

use App\Services\Server\DiskCleaner\AbstractCleanupTarget;
use App\Services\Server\ServerOpsResult;

/** Old files in /tmp and /var/tmp (older than the configured age). */
class TmpTarget extends AbstractCleanupTarget
{
    public function key(): string
    {
        return 'tmp';
    }

    public function group(): string
    {
        return 'temp';
    }

    public function method(): string
    {
        return 'delete';
    }

    public function available(): bool
    {
        return count($this->dirs()) > 0;
    }

    public function paths(): array
    {
        $days = (int) config('server.disk_cleaner.tmp_days', 7);

        return array_map(fn (string $dir) => "{$dir}/* (older than {$days}d)", $this->dirs());
    }

    public function estimate(): int
    {
        return $this->sumSizes($this->findArgs(delete: false));
    }

    public function clean(): ServerOpsResult
    {
        return $this->serverOps->run(
            $this->findArgs(delete: true),
            ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()],
            120,
        );
    }

    /**
     * @return array<int, string>
     */
    private function dirs(): array
    {
        return array_values(array_filter(['/tmp', '/var/tmp'], 'is_dir'));
    }

    /**
     * @return array<int, string>
     */
    private function findArgs(bool $delete): array
    {
        $days = (int) config('server.disk_cleaner.tmp_days', 7);
        // `-xdev` for the same reason as the rotated logs: /tmp is a common
        // mount point, and anything mounted under it belongs to something else.
        $base = ['find', ...$this->dirs(), '-xdev', '-type', 'f', '-mtime', "+{$days}"];

        return [...$base, ...($delete ? ['-delete'] : ['-printf', "%s\n"])];
    }
}
