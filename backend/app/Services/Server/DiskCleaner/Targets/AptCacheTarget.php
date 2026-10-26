<?php

namespace App\Services\Server\DiskCleaner\Targets;

use App\Services\Server\DiskCleaner\AbstractCleanupTarget;
use App\Services\Server\DiskCleaner\KeepDays;
use App\Services\Server\ServerOpsResult;

/** Downloaded .deb package files in the apt cache (`apt-get clean`, or those older than the keep choice). */
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

    public function estimate(?int $keepDays = null): int
    {
        $days = $keepDays ?? KeepDays::DELETE_ALL;

        return $this->estimates([$days])[$days];
    }

    /** Delete all is `apt-get clean`, so it is priced as the whole directory, as it always was. */
    public function estimates(array $choices): array
    {
        $packages = array_diff($choices, [KeepDays::DELETE_ALL]) !== [] ? $this->scan($this->packages(), '%C@') : [];

        return collect($choices)
            ->mapWithKeys(fn (int $days) => [$days => $days === KeepDays::DELETE_ALL
                ? $this->du(self::DIR)
                : $this->sumOlderThan($packages, KeepDays::cutoff($days))])
            ->all();
    }

    /**
     * Keep N days removes the downloads older than N days and leaves apt's
     * lock and `partial/` alone; delete all (and `null`, which is what this
     * always did) is apt's own clean.
     */
    public function clean(?int $keepDays = null): ServerOpsResult
    {
        $days = $keepDays ?? KeepDays::DELETE_ALL;

        if ($days !== KeepDays::DELETE_ALL) {
            return $this->serverOps->run(
                [...$this->packages(), ...KeepDays::findAge($days, '-cmin'), '-delete'],
                ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()],
                120,
            );
        }

        return $this->serverOps->apt(
            ['apt-get', 'clean'],
            ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()],
            120,
        );
    }

    /**
     * The cached packages, by the time they *arrived*. Not their modification
     * time: apt stamps a download with the mirror's Last-Modified, so a package
     * fetched this morning can carry a date from last year — "keep 7 days" by
     * mtime would delete what was just downloaded. The inode change time is
     * set when apt moves the finished file into place.
     *
     * @return array<int, string>
     */
    private function packages(): array
    {
        return ['find', self::DIR, '-maxdepth', '1', '-type', 'f', '-name', '*.deb'];
    }
}
