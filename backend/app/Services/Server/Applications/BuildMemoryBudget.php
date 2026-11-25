<?php

namespace App\Services\Server\Applications;

/**
 * How much heap a client-side asset build may use, in megabytes.
 *
 * Node's default heap size assumes a developer laptop, so on a small VPS a webpack build grows
 * until the kernel SIGKILLs it: no stderr, exit status 137, and the panel can only say "the build
 * failed". Under a cap V8 collects harder, or fails with `JavaScript heap out of memory` and a
 * message.
 *
 * Sized from `MemAvailable`, never `MemTotal`: the box already runs a web server, a database and
 * other sites, and `MemTotal` could authorise a heap larger than the free memory.
 */
class BuildMemoryBudget
{
    /** A smaller ceiling makes V8 thrash the collector instead of failing fast. */
    private const MINIMUM_MB = 512;

    /** Above this no cap is set: V8's own defaults are fine on a large server. */
    private const MAXIMUM_MB = 4096;

    /** Leave room for npm, the web server, the database and the page cache. */
    private const SHARE = 0.75;

    /**
     * The `NODE_OPTIONS` value for a build; empty (Node's defaults) when `/proc/meminfo` cannot be
     * read, since a cap from an unread number is worse than no cap.
     *
     * @return array<string, string> environment to merge into the build
     */
    public function nodeOptions(): array
    {
        $megabytes = $this->heapMegabytes();

        return $megabytes === null
            ? []
            : ['NODE_OPTIONS' => "--max-old-space-size={$megabytes}"];
    }

    /**
     * The cap in megabytes, or null when it should not be set.
     */
    public function heapMegabytes(): ?int
    {
        $available = $this->availableBytes();

        if ($available === null || $available <= 0) {
            return null;
        }

        return $this->clamp((int) floor($available * self::SHARE / 1048576));
    }

    /**
     * Public and pure, so the arithmetic is tested without a fake `/proc`.
     */
    public function clamp(int $megabytes): ?int
    {
        if ($megabytes < self::MINIMUM_MB) {
            // Still set the minimum: on a tight box the cap turns a SIGKILL into a readable error.
            return self::MINIMUM_MB;
        }

        return $megabytes > self::MAXIMUM_MB ? null : $megabytes;
    }

    /**
     * `MemAvailable` in bytes, or null when it cannot be read.
     *
     * Read from the file (no privilege needed); `server.proc_dir` lets a test point at a fixture.
     */
    private function availableBytes(): ?int
    {
        $path = rtrim((string) config('server.proc_dir', '/proc'), '/').'/meminfo';

        if (! is_file($path)) {
            return null;
        }

        $contents = (string) @file_get_contents($path);

        if (preg_match('/^MemAvailable:\s+(\d+)/m', $contents, $match) !== 1) {
            return null;
        }

        // /proc/meminfo reports kB.
        return (int) $match[1] * 1024;
    }
}
