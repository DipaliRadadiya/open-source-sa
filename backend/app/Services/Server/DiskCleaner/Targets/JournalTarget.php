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
     * What the clean would actually reclaim, not the size of the journal.
     *
     * A time vacuum removes only **archived** files (`@` in the name, e.g. `system@2cfa….journal`;
     * the open `system.journal` is never removed). The trailing glob also catches `.journal~`.
     * `journalctl --disk-usage` counts active files too, so it would promise more than the clean frees.
     *
     * Delete all is priced as every archived file, whatever its age: the rotate replaces each open
     * file with a new preallocated one, so those free nothing net.
     *
     * An archived journal's mtime is its newest entry, the same timestamp `--vacuum-time` compares.
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
     * `--vacuum-time=1s` would race: just-sealed files hold fresh entries. A size vacuum ignores
     * time, so every archived file goes and only the new, empty, open files remain.
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

    /** The keep choice, defaulting to the recommended week for requests that predate the choice. */
    private function days(?int $keepDays): int
    {
        return $keepDays ?? KeepDays::RECOMMENDED;
    }
}
