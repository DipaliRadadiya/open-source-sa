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
        return ['systemd journal (/var/log/journal), archived entries older than '.$this->days().' days'];
    }

    /**
     * What a vacuum would actually reclaim — not the size of the journal.
     *
     * This used to report `journalctl --disk-usage`, which is *"archived and
     * active journals"*: everything. But `clean()` runs `--vacuum-time`, which
     * removes only **archived** files whose entries are older than the window.
     * So the screen offered "Journal — 120 MB", the button freed nothing, and
     * from outside that is indistinguishable from a cleaner that is broken. It
     * is the shape of estimate this class exists to avoid: a number that
     * promises what the action cannot deliver.
     *
     * Archived files are the ones with `@` in the name — `system.journal` is
     * the open one and is never removed, `system@2cfa…-000657….journal` is
     * sealed. The trailing glob catches `.journal~`, which is a file journald
     * gave up on and which a vacuum also collects.
     *
     * `-mtime` is not an approximation here: an archived journal's mtime is
     * when it was last written, which is the same "newest entry" timestamp
     * `--vacuum-time` compares against.
     */
    public function estimate(): int
    {
        return $this->sumSizes([
            'find', '/var/log/journal',
            '-type', 'f',
            '-name', '*@*.journal*',
            '-mtime', '+'.$this->days(),
            '-printf', '%s\n',
        ]);
    }

    public function clean(): ServerOpsResult
    {
        return $this->serverOps->run(
            ['journalctl', '--vacuum-time='.$this->days().'d'],
            ['feature' => 'disk_cleaner', 'op' => 'clean', 'target' => $this->key()],
            120,
        );
    }

    /** The retention window, read once so the estimate and the clean cannot disagree. */
    private function days(): int
    {
        return max(1, (int) config('server.disk_cleaner.journal_days', 7));
    }
}
