<?php

namespace App\Services\Server;

/**
 * Starts a command and hands back its stdout and stderr as streams.
 *
 * This exists as its own class for one reason: `proc_open` is invisible to
 * `Process::fake()`. {@see ServerOps::stream()} used to drive Symfony's
 * `Process`, which the facade can intercept, and moving to `proc_open` — for
 * the memory reasons that method documents — took every download test with it.
 * They did not fail; they started running `sudo runuser … cat` against the
 * machine running the suite, which is worse.
 *
 * So the spawn is a seam and the *reading* is not. `ServerOps::stream()` keeps
 * the part that was actually wrong (the select loop, the bounded reads, the
 * backpressure) and that part is exercised against real commands in
 * `StreamDownloadTest`. A fake here replaces only "what did this command
 * print", which is the one thing a test legitimately wants to decide.
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
