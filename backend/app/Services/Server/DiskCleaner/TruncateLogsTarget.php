<?php

namespace App\Services\Server\DiskCleaner;

use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Str;

/**
 * Empties (truncates, never deletes) current log files found in a set of
 * directories.
 *
 * The files are found through the server, not with PHP's glob(): glob runs as
 * the panel account, and the logs that grow largest are the ones it cannot
 * list — /usr/local/lsws/logs is root:nogroup 0750, and every site's own
 * `logs/` is root:{site user} 0750. Found on a live OpenLiteSpeed server
 * (2026-09-23): the scan offered 2.8 MB of Redis/UFW/fail2ban logs and none of
 * the 4 MB of OpenLiteSpeed logs or any site's access log.
 *
 * `-type f` without -L does not follow symlinks, so a link is never listed and
 * never emptied; `truncate --no-create` does not create a file that vanished
 * between the listing and the clean. Truncate rather than delete: a writer
 * holding an unlinked file keeps its space until it restarts, while a
 * truncated one keeps appending to the same file.
 */
abstract class TruncateLogsTarget extends AbstractCleanupTarget
{
    /**
     * Directories to look in, each with the file-name pattern to match.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    abstract protected function locations(): array;

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
        return $this->files() !== [];
    }

    public function paths(): array
    {
        return array_keys($this->files());
    }

    public function estimate(): int
    {
        return array_sum($this->files());
    }

    public function clean(): ServerOpsResult
    {
        $files = array_keys($this->files());

        if ($files === []) {
            return new ServerOpsResult(true, (string) Str::uuid());
        }

        return $this->serverOps->run(
            ['truncate', '--no-create', '-s', '0', ...$files],
            ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()],
            60,
        );
    }

    /**
     * Current log files and their sizes, keyed by path.
     *
     * @return array<string, int>
     */
    protected function files(): array
    {
        $files = [];

        foreach ($this->locations() as [$directory, $pattern]) {
            $result = $this->serverOps->run(
                ['find', $directory, '-maxdepth', '1', '-type', 'f', '-name', $pattern, '-printf', "%s\t%p\n"],
                ['feature' => 'disk_cleaner', 'op' => 'list', 'target' => $this->key()],
                timeout: 30,
                // A directory that does not exist on this stack (no Apache on
                // an nginx box) is an ordinary answer, not a failure.
                expectedExitCodes: [1],
            );

            foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
                [$size, $path] = array_pad(explode("\t", $line, 2), 2, null);

                if ($path !== null && is_numeric($size)) {
                    $files[$path] = (int) $size;
                }
            }
        }

        ksort($files);

        return $files;
    }
}
