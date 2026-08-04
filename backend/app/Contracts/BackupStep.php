<?php

namespace App\Contracts;

use App\Services\Server\Backups\BackupContext;

/**
 * One stage of a backup run (strategy).
 *
 * Steps are ordered and each records what it did into the backup's manifest,
 * so a failure names the stage rather than producing "the backup failed" —
 * the difference between an operator knowing the upload broke and knowing
 * only that something did.
 *
 * A step must clean up after itself on failure. A dump left in /tmp after a
 * failed upload fills the disk that the next attempt needs.
 */
interface BackupStep
{
    /** Stable machine key (e.g. `dump_database`). Also the i18n key. */
    public function key(): string;

    /** Whether this step applies — a files-only target skips the dump. */
    public function appliesTo(BackupContext $context): bool;

    /**
     * Do the work. Throws on failure; the runner catches, records the step
     * that threw, and runs cleanup.
     */
    public function run(BackupContext $context): void;

    /**
     * Undo whatever local state this step created. Called for every step that
     * ran, in reverse, whether the run succeeded or failed — the artefacts are
     * on the destination by then, and the copies on disk are just cost.
     */
    public function cleanup(BackupContext $context): void;
}
