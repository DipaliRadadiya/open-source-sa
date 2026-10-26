<?php

namespace App\Services\Server\DiskCleaner;

use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Str;

/**
 * Empties (truncates, never deletes) current log files found in a set of
 * directories, and — when the category has its own rotated copies and a keep
 * choice is given — deletes the copies older than that choice.
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
        return $this->files() !== [] || $this->archives() !== [];
    }

    public function paths(): array
    {
        $patterns = array_map(
            fn (string $directory) => "{$directory}/{$this->archivePattern()}",
            array_values(array_unique(array_map('dirname', array_keys($this->archives())))),
        );

        return [...array_keys($this->files()), ...$patterns];
    }

    /**
     * Rotated copies beside the current files that this category also removes
     * once a keep choice is given, or null when another category owns them
     * (the server's own archives are under /var/log, which `rotated_logs`
     * covers).
     */
    protected function archivePattern(): ?string
    {
        return null;
    }

    /**
     * `null` is the request from before the keep choice existed: every
     * current file emptied and nothing else, exactly as it was.
     */
    public function estimate(?int $keepDays = null): int
    {
        if ($keepDays === null) {
            return array_sum(array_column($this->files(), 0));
        }

        return $this->estimates([$keepDays])[$keepDays];
    }

    public function estimates(array $choices): array
    {
        $files = [...array_values($this->files()), ...array_values($this->archives())];

        return collect($choices)
            ->mapWithKeys(fn (int $days) => [$days => $this->sumOlderThan($files, KeepDays::cutoff($days))])
            ->all();
    }

    /**
     * A current file is emptied only when nothing has been written to it for
     * the whole window — then every line in it is older than the choice. One
     * still being written holds today's lines, and keeping N days of a file
     * means keeping it: there is no safe way to cut the old lines out of a
     * file a web server is appending to. Its older days are in the rotated
     * copies, which is where the choice does its work.
     */
    public function clean(?int $keepDays = null): ServerOpsResult
    {
        $cutoff = $keepDays === null ? null : KeepDays::cutoff($keepDays);
        $older = fn (array $file) => $cutoff === null || $file[1] < $cutoff;

        $files = array_keys(array_filter($this->files(), $older));
        $archives = $keepDays === null ? [] : array_keys(array_filter($this->archives(), $older));

        $context = ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()];
        $result = new ServerOpsResult(true, (string) Str::uuid());

        if ($files !== []) {
            $result = $this->serverOps->run(['truncate', '--no-create', '-s', '0', ...$files], $context, 60);
        }

        // Deleted, not truncated: nothing holds a rotated copy open. Listed
        // with `-type f`, so a path is a regular file, and `rm` on a path that
        // has since been swapped for a link removes the link, never its target.
        foreach (array_chunk($archives, 200) as $chunk) {
            if ($result->failed()) {
                break;
            }

            $result = $this->serverOps->run(['rm', '-f', '--', ...$chunk], $context, 60);
        }

        return $result;
    }

    /**
     * Current log files, keyed by path: [size, modified].
     *
     * @return array<string, array{0: int, 1: int}>
     */
    protected function files(): array
    {
        return $this->list();
    }

    /**
     * Rotated copies, keyed by path: [size, modified]. Empty when this
     * category has none of its own.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    protected function archives(): array
    {
        $pattern = $this->archivePattern();

        return $pattern === null ? [] : $this->list($pattern);
    }

    /**
     * @param  string|null  $override  a pattern to list instead of each location's own
     * @return array<string, array{0: int, 1: int}>
     */
    private function list(?string $override = null): array
    {
        $files = [];

        foreach ($this->locations() as [$directory, $pattern]) {
            $result = $this->serverOps->run(
                ['find', $directory, '-maxdepth', '1', '-type', 'f', '-name', $override ?? $pattern, '-printf', "%s\t%T@\t%p\n"],
                ['feature' => 'disk_cleaner', 'op' => 'list', 'target' => $this->key()],
                timeout: 30,
                // A directory that does not exist on this stack (no Apache on
                // an nginx box) is an ordinary answer, not a failure.
                expectedExitCodes: [1],
            );

            foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
                [$size, $modified, $path] = array_pad(explode("\t", $line, 3), 3, null);

                if ($path !== null && is_numeric($size) && is_numeric($modified)) {
                    $files[$path] = [(int) $size, (int) $modified];
                }
            }
        }

        ksort($files);

        return $files;
    }
}
