<?php

namespace App\Services\Server;

/**
 * How many CPUs this machine has.
 *
 * One copy, shared by the metrics screen and the CPU-limit validation, so they cannot disagree.
 *
 * Read from `cpuinfo` instead of spawning `nproc` (it reads the same file), as this runs on every
 * form submission carrying a CPU limit. Read through `server.proc_dir`, not a literal `/proc`, so
 * tests can point it at a fixture directory.
 *
 * Counts what the kernel exposes, not what the machine is entitled to (a VM with a hypervisor
 * quota lists the host's cores). That is the number `docker run --cpus` is measured against.
 */
class HostCpus
{
    /**
     * The count of `processor` entries, floored at one: a zero would make every CPU limit invalid,
     * and an unreadable `cpuinfo` is a reason to stop bounding the value, not to claim no CPUs.
     */
    public function count(): int
    {
        $path = rtrim((string) config('server.proc_dir', '/proc'), '/').'/cpuinfo';

        if (! is_file($path)) {
            return 1;
        }

        // Only lines that start with `processor`: the word also appears in model names
        // ("Intel(R) Xeon(R) processor") and would double the count.
        return max(1, preg_match_all('/^processor\s*:/m', (string) @file_get_contents($path)));
    }
}
