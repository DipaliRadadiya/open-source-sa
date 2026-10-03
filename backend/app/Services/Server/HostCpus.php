<?php

namespace App\Services\Server;

/**
 * How many CPUs this machine has.
 *
 * One copy, because two would drift: the metrics screen and the CPU-limit
 * validation both need this number, and a validator that disagreed with the
 * dashboard about the size of the box would refuse a limit the panel had just
 * told somebody they had.
 *
 * Read from `cpuinfo` rather than by shelling out to `nproc`. This is consulted on
 * every form submission carrying a CPU limit, and a process spawn per validated
 * field is a cost with nothing to show for it — `nproc` reads the same file.
 *
 * **Through `server.proc_dir`, not a literal `/proc`.** That config key is the seam
 * the metrics tests point at a fixture directory, and reading `/proc` directly made
 * this class answer the runner's real core count while the dashboard beside it
 * answered the fixture's — which the Dashboard test caught immediately, reporting 8
 * where the fixture says 2. A shared number read two different ways is not a shared
 * number.
 *
 * **Counts what the kernel exposes, not what the machine is entitled to.** On a VM
 * with a CPU quota at the hypervisor, `cpuinfo` lists the host's cores and the guest
 * gets a fraction of them. That is the same number `docker run --cpus` is measured
 * against, so it is the right bound for this purpose even though it can overstate
 * what the box will actually deliver.
 */
class HostCpus
{
    /**
     * The count of `processor` entries, floored at one.
     *
     * Floored because a zero would make every CPU limit invalid and lock the field
     * rather than fail open: an unreadable `cpuinfo` is a reason to stop bounding
     * the value, not a reason to claim the machine has no CPUs.
     */
    public function count(): int
    {
        $path = rtrim((string) config('server.proc_dir', '/proc'), '/').'/cpuinfo';

        if (! is_file($path)) {
            return 1;
        }

        return max(1, substr_count((string) @file_get_contents($path), 'processor'));
    }
}
