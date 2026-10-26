<?php

namespace App\Services\Server\DiskCleaner\Targets;

use App\Services\Server\DiskCleaner\AbstractCleanupTarget;
use App\Services\Server\DiskCleaner\KeepDays;
use App\Services\Server\ServerOpsResult;

/** systemd journal entries older than the keep choice, or all of them (`journalctl --vacuum-*`). */
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

    /**
     * What the clean would actually reclaim — not the size of the journal.
     *
     * This used to report `journalctl --disk-usage`, which is *"archived and
     * active journals"*: everything. But a time vacuum removes only
     * **archived** files whose entries are older than the window. So the screen
     * offered "Journal — 120 MB", the button freed nothing, and from outside
     * that is indistinguishable from a cleaner that is broken. It is the shape
     * of estimate this class exists to avoid: a number that promises what the
     * action cannot deliver.
     *
     * Archived files are the ones with `@` in the name — `system.journal` is
     * the open one and a time vacuum never removes it, `system@2cfa…-000657….journal`
     * is sealed. The trailing glob catches `.journal~`, which is a file journald
     * gave up on and which a vacuum also collects.
     *
     * Delete all is priced as every archived file, whatever its age — not the
     * open ones too. The rotate seals and removes them, but journald replaces
     * each with a new file preallocated to the same 8 MB, so they free nothing
     * net. Measured on a fresh box (2026-10-26): pricing every file offered
     * 56 MB and the clean freed 24 MB, the size of the archived files. Open
     * files left behind by an earlier machine id are not archived and no
     * vacuum removes them either.
     *
     * The modification time is not an approximation here: an archived
     * journal's mtime is when it was last written, which is the same "newest
     * entry" timestamp `--vacuum-time` compares against.
     */
    public function estimate(?int $keepDays = null): int
    {
        $days = $this->days($keepDays);

        return $this->estimates([$days])[$days];
    }

    public function estimates(array $choices): array
    {
        $archived = $this->scan(['find', '/var/log/journal', '-type', 'f', '-name', '*@*.journal*']);

        return collect($choices)
            ->mapWithKeys(fn (int $days) => [$days => $this->sumOlderThan($archived, KeepDays::cutoff($days))])
            ->all();
    }

    /**
     * Delete all is a rotate then a size vacuum to one byte, in one call.
     *
     * `--vacuum-time=1s` would race: the files the rotate has just sealed hold
     * entries written a moment ago, so a one-second window can keep them. A
     * size vacuum ignores time and removes archived files until the journal is
     * under the limit, which one byte never is — so every archived file goes,
     * and only the new, empty, open files remain.
     */
    public function clean(?int $keepDays = null): ServerOpsResult
    {
        $days = $this->days($keepDays);

        return $this->serverOps->run(
            $days === KeepDays::DELETE_ALL
                ? ['journalctl', '--rotate', '--vacuum-size=1']
                : ['journalctl', '--vacuum-time='.$days.'d'],
            ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()],
            120,
        );
    }

    /**
     * The keep choice, with the week this category always kept standing in
     * for a request or schedule from before the choice existed. Read once per
     * call so the estimate and the clean cannot disagree.
     */
    private function days(?int $keepDays): int
    {
        return $keepDays ?? KeepDays::RECOMMENDED;
    }
}
