<?php

namespace App\Contracts;

use App\Services\Server\Restores\RestoreContext;

/**
 * One stage of a restore (strategy) — the same shape as BackupStep, and
 * deliberately so: the two read as mirror images.
 *
 * The ordering rule that matters is that **every step which can fail without
 * consequence comes first**. Download, verify and the safety backup all run
 * before a single byte of the live application is touched, so the six most
 * likely failures leave the site exactly as it was.
 *
 * `cleanup()` here means something stronger than it does for a backup: it is
 * the undo path for a half-applied destructive change, not just deleting
 * temporary files.
 */
interface RestoreStep
{
    /** Stable machine key (e.g. `swap_files`). Also the i18n key. */
    public function key(): string;

    /** Whether this step applies — a database-only restore skips the swap. */
    public function appliesTo(RestoreContext $context): bool;

    /** Do the work. Throws on failure; the runner records the step that threw. */
    public function run(RestoreContext $context): void;

    /**
     * Called for every step that ran, in reverse, whether the restore
     * succeeded or failed. `$failed` tells the step which it was — a step that
     * stopped a process must start it again on failure, but must not
     * second-guess a success.
     */
    public function cleanup(RestoreContext $context, bool $failed): void;
}
