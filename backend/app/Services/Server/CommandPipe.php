<?php

namespace App\Services\Server;

/**
 * Starts a command and hands back its stdout and stderr as streams.
 *
 * Its own class because `proc_open` is invisible to `Process::fake()`: without this seam, tests
 * of {@see ServerOps::stream()} would run real `sudo runuser … cat` commands on the test machine.
 *
 * Only the spawn is faked; the reading (select loop, bounded reads, backpressure) stays in
 * `ServerOps::stream()` and is tested against real commands in `StreamDownloadTest`.
 */
class CommandPipe
{
    /**
     * @param  array<int, string>  $command
     * @return array{0: resource|null, 1: resource|null, 2: resource|null}
     *                                                                     the process handle, stdout, stderr — all null when the spawn failed.
     */
    public function open(array $command): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            return [null, null, null];
        }

        return [$process, $pipes[1], $pipes[2]];
    }

    /**
     * Wait for the command and give back its exit status.
     *
     * Paired with `open()` so a fake can answer both without a real child.
     *
     * @param  resource  $process
     */
    public function close($process): int
    {
        return proc_close($process);
    }

    /**
     * @param  resource  $process
     */
    public function terminate($process): void
    {
        proc_terminate($process);
    }
}
