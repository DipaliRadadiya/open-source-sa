<?php

namespace App\Services\Server\DiskCleaner\Targets;

use App\Services\Server\DiskCleaner\AbstractCleanupTarget;
use App\Services\Server\ServerOpsResult;

/** systemd journal entries older than the retention window (`journalctl --vacuum-time`). */
class JournalTarget extends AbstractCleanupTarget
{
    public function key(): string
    {
        return 'journal';
    }

    public function group(): string
    {
        return 'logs';
    }

    public function method(): string
    {
        return 'command';
    }

    public function available(): bool
    {
        return is_file('/usr/bin/journalctl') || is_dir('/var/log/journal');
    }

    public function paths(): array
    {
        return ['systemd journal (/var/log/journal)'];
    }

    public function estimate(): int
    {
        $output = $this->serverOps->run(
            ['journalctl', '--disk-usage'],
            ['feature' => 'disk_cleaner', 'op' => 'estimate', 'target' => $this->key()],
        )->output();

        // "Archived and active journals take up 120.0M in the file system."
        if (preg_match('/take up ([\d.,]+)([KMGT]?)/i', $output, $m)) {
            return $this->binaryBytes($m[1], $m[2]);
        }

        return 0;
    }

    public function clean(): ServerOpsResult
    {
        $days = (int) config('server.disk_cleaner.journal_days', 7);

        return $this->serverOps->run(
            ['journalctl', "--vacuum-time={$days}d"],
            ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()],
            120,
        );
    }
}
