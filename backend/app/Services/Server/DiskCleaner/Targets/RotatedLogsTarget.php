<?php

namespace App\Services\Server\DiskCleaner\Targets;

use App\Services\Server\DiskCleaner\AbstractCleanupTarget;
use App\Services\Server\ServerOpsResult;

/** Old rotated/compressed log archives under /var/log (safe to delete). */
class RotatedLogsTarget extends AbstractCleanupTarget
{
    public function key(): string
    {
        return 'rotated_logs';
    }

    public function group(): string
    {
        return 'logs';
    }

    public function method(): string
    {
        return 'delete';
    }

    public function available(): bool
    {
        return is_dir('/var/log');
    }

    public function paths(): array
    {
        return ['/var/log/**/*.gz', '/var/log/**/*.[0-9]', '/var/log/**/*.old'];
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
    private function findArgs(bool $delete): array
    {
        $base = [
            'find', '/var/log', '-type', 'f',
            '(', '-name', '*.gz', '-o', '-regex', '.*\.[0-9]+', '-o', '-name', '*.old', ')',
        ];

        return [...$base, ...($delete ? ['-delete'] : ['-printf', "%s\n"])];
    }
}
