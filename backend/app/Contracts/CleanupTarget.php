<?php

namespace App\Contracts;

use App\Services\Server\ServerOpsResult;

/**
 * A single disk-cleanup category (strategy). Each target knows how to detect
 * whether it applies to this box, estimate the reclaimable space, describe
 * exactly what it touches, and perform the cleanup — all server-side. The
 * client only ever references a target by its `key`; paths are never accepted
 * from the client.
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
     * The concrete paths/globs (or a friendly target label for command-based
     * targets) the cleanup will touch — shown to the user before they confirm.
     *
     * @return array<int, string>
     */
    public function paths(): array;

    /** Reclaimable bytes, estimated live. */
    public function estimate(): int;

    /** Perform the cleanup. Never throws — caller inspects the result. */
    public function clean(): ServerOpsResult;
}
