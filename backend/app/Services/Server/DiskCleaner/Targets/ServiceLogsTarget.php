<?php

namespace App\Services\Server\DiskCleaner\Targets;

use App\Services\Server\DiskCleaner\AbstractCleanupTarget;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Str;

/**
 * Empties (truncates, never deletes) the active current log files of running
 * services — the big growers like nginx/apache access logs. The set is a
 * config-driven glob list (`server.disk_cleaner.service_log_globs`) covering
 * our supported services; only files that exist are touched.
 *
 * Truncate (not delete) because an open log file that is unlinked keeps its
 * space until the writer restarts and breaks its file handle; truncating
 * reclaims the space safely while the service keeps appending.
 */
class ServiceLogsTarget extends AbstractCleanupTarget
{
    public function key(): string
    {
        return 'service_logs';
    }

    public function group(): string
    {
        return 'logs';
    }

    public function method(): string
    {
        return 'truncate';
    }

    public function available(): bool
    {
        return count($this->files()) > 0;
    }

    public function paths(): array
    {
        return $this->files();
    }

    public function estimate(): int
    {
        $sum = 0;
        foreach ($this->files() as $file) {
            $sum += (int) (filesize($file) ?: 0);
        }

        return $sum;
    }

    public function clean(): ServerOpsResult
    {
        $files = $this->files();

        if (empty($files)) {
            return new ServerOpsResult(true, (string) Str::uuid());
        }

        return $this->serverOps->run(
            ['truncate', '-s', '0', ...$files],
            ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()],
            60,
        );
    }

    /**
     * Existing active log files matched by the configured globs.
     *
     * @return array<int, string>
     */
    private function files(): array
    {
        $files = [];
        foreach ((array) config('server.disk_cleaner.service_log_globs', []) as $glob) {
            foreach (glob($glob) ?: [] as $match) {
                if (is_file($match)) {
                    $files[] = $match;
                }
            }
        }

        return array_values(array_unique($files));
    }
}
