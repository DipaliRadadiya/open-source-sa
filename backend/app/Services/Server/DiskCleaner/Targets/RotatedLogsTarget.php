<?php

namespace App\Services\Server\DiskCleaner\Targets;

use App\Services\Server\DiskCleaner\AbstractCleanupTarget;
use App\Services\Server\DiskCleaner\KeepDays;
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
        // Shown to the user before they clean, so it says what is excluded too.
        return ['/var/log/**/*.gz', '/var/log/**/*.[0-9]', '/var/log/**/*.old', 'excluding /var/log/mysql'];
    }

    public function estimate(?int $keepDays = null): int
    {
        $days = $keepDays ?? KeepDays::DELETE_ALL;

        return $this->estimates([$days])[$days];
    }

    /** One listing prices every choice: an archive's mtime is when it was rotated out. */
    public function estimates(array $choices): array
    {
        $files = $this->scan($this->findArgs());

        return collect($choices)
            ->mapWithKeys(fn (int $days) => [$days => $this->sumOlderThan($files, KeepDays::cutoff($days))])
            ->all();
    }

    /** Before the choice existed this deleted every archive, so `null` still does. */
    public function clean(?int $keepDays = null): ServerOpsResult
    {
        return $this->serverOps->run(
            [...$this->findArgs(), ...KeepDays::findAge($keepDays ?? KeepDays::DELETE_ALL), '-delete'],
            ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()],
            120,
        );
    }

    /**
     * The selection, without an action: clean appends its age test and `-delete`,
     * the estimate its `-printf`.
     *
     * @return array<int, string>
     */
    private function findArgs(): array
    {
        // `-delete` implies `-depth`, under which `-prune` is ignored: hence `! -path` below.
        return [
            'find', '/var/log',
            // Never leave the filesystem /var/log is on: a separate volume or bind mount
            // underneath it is somebody else's data, and this deletes what it finds.
            '-xdev',
            '-regextype', 'posix-extended',
            '-type', 'f',
            // MySQL/MariaDB binary logs (`mysql-bin.000001`) live here. Deleting them breaks
            // replication and point-in-time recovery; use `PURGE BINARY LOGS` instead.
            '!', '-path', '/var/log/mysql/*',
            '(',
            '-name', '*.gz',
            // One or two digits: logrotate counts 1..99, binary logs are six-digit. Not
            // `[0-9]+`: that, plus the path exclusion above, are two independent guards.
            '-o', '-regex', '.*\.[0-9]{1,2}',
            '-o', '-name', '*.old',
            ')',
        ];
    }
}
