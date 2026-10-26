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
     * The selection, without an action: the clean appends its age test and
     * `-delete`, the estimate its `-printf`.
     *
     * @return array<int, string>
     */
    private function findArgs(): array
    {
        // `-delete` implies `-depth`, and `-prune` is silently ignored under
        // `-depth` — hence the `! -path` exclusion below rather than a prune.
        return [
            'find', '/var/log',
            // Never leave the filesystem /var/log is on. A separate volume or a
            // bind mount underneath it is somebody else's data, and this
            // command deletes what it finds.
            '-xdev',
            '-regextype', 'posix-extended',
            '-type', 'f',
            // MySQL and MariaDB write binary logs here when binary logging is
            // on — Debian's shipped config points `log_bin` at this directory.
            // They are named `mysql-bin.000001`, so a match on "ends in digits"
            // deletes them: replication and point-in-time recovery break,
            // `mysql-bin.index` still lists the files, and because mysqld holds
            // them open the space is not even freed. Binary logs are removed
            // with `PURGE BINARY LOGS`, never from underneath the server.
            '!', '-path', '/var/log/mysql/*',
            '(',
            '-name', '*.gz',
            // One or two digits: logrotate counts 1..99, binary logs are
            // six-digit and zero-padded. Deliberately narrower than `[0-9]+`,
            // which is what swept the binary logs in — the path exclusion above
            // and this bound are two independent guards, because either one
            // alone is a single point of failure for deleting a database's log.
            '-o', '-regex', '.*\.[0-9]{1,2}',
            '-o', '-name', '*.old',
            ')',
        ];
    }
}
