<?php

namespace App\Contracts;

use App\Services\Server\DiskCleaner\KeepDays;
use App\Services\Server\ServerOpsResult;

/**
 * A single disk-cleanup category (strategy): detects whether it applies, estimates the
 * reclaimable space, lists what it touches, and cleans. The client only references a
 * target by its `key`; paths are never accepted from the client.
 */
interface CleanupTarget
{
    /** Stable machine key (e.g. `apt_cache`). Also the i18n key. */
    public function key(): string;

    /** Display group for the UI (`package|logs|temp`). */
    public function group(): string;

    /** How it reclaims space: `delete｜truncate｜command` (UI badge). */
    public function method(): string;

    /** True when this target's dependency is present on the server. */
    public function available(): bool;

    /** Safe to pre-check / run unattended. */
    public function safe(): bool;

    /**
     * The paths/globs (or a label for command-based targets) the cleanup touches, shown
     * to the user before they confirm.
     *
     * @return array<int, string>
     */
    public function paths(): array;

    /**
     * Whether this category can keep recent data. One that cannot (unused packages have
     * no age) ignores the keep choice and always removes all.
     */
    public function keepsDays(): bool;

    /**
     * Reclaimable bytes, estimated live, for one keep choice ({@see KeepDays}).
     * `null` = no keep choice.
     */
    public function estimate(?int $keepDays = null): int;

    /**
     * Reclaimable bytes for every choice in one pass, keyed by choice.
     *
     * @param  array<int, int>  $choices
     * @return array<int, int>
     */
    public function estimates(array $choices): array;

    /** Perform the cleanup. Never throws — caller inspects the result. */
    public function clean(?int $keepDays = null): ServerOpsResult;
}
