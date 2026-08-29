<?php

namespace App\Services\Server\Applications;

/**
 * How much heap a client-side asset build may use, in megabytes.
 *
 * Node does not cap its own heap to fit the machine: V8 sizes the old space
 * from a compiled-in default that assumes a developer laptop, so on a small
 * VPS a webpack build grows until the **kernel** stops it. That failure is the
 * worst possible shape — SIGKILL produces no stderr, no exception and no exit
 * status of its own (the shell reports 137), so the panel sees a command that
 * simply stopped, and every layer above it can only say "the build failed".
 *
 * Capping the heap converts that into something survivable *and* something
 * legible. Under a cap V8 collects garbage harder rather than allocating more,
 * which on a 2 GB box is usually the difference between a build that finishes
 * and one that is killed; and if the work genuinely does not fit, V8 raises
 * `JavaScript heap out of memory` and exits non-zero **with a message** rather
 * than vanishing. Either outcome beats a silent kill.
 *
 * NodeBB's own maintainers point at this same flag for memory-constrained
 * hosts, so this is upstream's answer rather than the panel inventing one.
 *
 * Sized from `MemAvailable`, never `MemTotal`: the number that matters is what
 * the kernel believes it can hand out right now, on a box already running a
 * web server, a database and every other site. `MemTotal` on the 2 GB machine
 * this was written for would authorise a heap larger than the free memory.
 */
class BuildMemoryBudget
{
    /**
     * Below this a cap is not worth setting — a build that cannot get 512 MB
     * is not going to be rescued by being told so more politely, and a very
     * small ceiling makes V8 thrash the collector instead of failing fast.
     */
    private const MINIMUM_MB = 512;

    /**
     * Above this the cap stops being useful: the build does not want 4 GB, and
     * a ceiling well past what it needs is the same as no ceiling at all.
     * Leaving it unset on a large server keeps V8's own defaults, which are
     * fine there — this class exists for the small end.
     */
    private const MAXIMUM_MB = 4096;

    /**
     * Leave room for everything the build is not: npm itself, the web server,
     * the database, and the page cache the compiler is about to lean on.
     * Handing V8 all of available memory just moves the kill a little later.
     */
    private const SHARE = 0.75;

    /**
     * The `NODE_OPTIONS` value for a build, or null to leave Node's defaults
     * alone.
     *
     * Null rather than a guess when `/proc/meminfo` cannot be read: this runs
     * on servers the panel did not build, and a cap derived from a number we
     * failed to read is worse than no cap.
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
            // Say the smallest useful thing rather than nothing: on a box this
            // tight the cap is what turns a SIGKILL into a readable error, and
            // that is exactly where the readable error is most needed.
            return self::MINIMUM_MB;
        }

        return $megabytes > self::MAXIMUM_MB ? null : $megabytes;
    }

    /**
     * `MemAvailable` in bytes, or null when it cannot be read.
     *
     * Read from the file rather than shelled out: this needs no privilege, and
     * `server.proc_dir` is already the seam the metrics code uses, so a test
     * points it at a fixture instead of at the machine running the suite.
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
