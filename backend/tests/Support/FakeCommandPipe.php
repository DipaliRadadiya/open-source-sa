<?php

namespace Tests\Support;

use App\Services\Server\CommandPipe;

/**
 * A `CommandPipe` backed by in-memory streams.
 *
 * `ServerOps::stream()` moved from Symfony's `Process` to `proc_open`, which
 * `Process::fake()` cannot see. Without this the download tests did not fail —
 * they started running `sudo runuser … cat` against the machine running the
 * suite, which is the failure mode worth avoiding rather than the one worth
 * asserting on.
 *
 * It delegates to a resolver rather than holding fixtures of its own, so a
 * suite that has already described a filesystem to `Process::fake()` describes
 * it once. Two fakes with two copies of the same fixture is how a test ends up
 * asserting against a file only one of them can see.
 *
 * Only the spawn is replaced. The select loop, the bounded reads and the
 * backpressure stay real, and are covered against real commands in
 * `StreamDownloadTest` — they are the part that was wrong.
 */
class FakeCommandPipe extends CommandPipe
{
    /**
     * Answers a command.
     *
     * @var null|callable(array<int, string>): array{0: string, 1: int} stdout, exit code
     */
    public static $resolver = null;

    /** @var list<string> every command opened */
    public static array $opened = [];

    private static int $lastExit = 0;

    public static function reset(): void
    {
        self::$resolver = null;
        self::$opened = [];
        self::$lastExit = 0;
    }

    public function open(array $command): array
    {
        self::$opened[] = implode(' ', $command);

        [$body, $exit] = self::$resolver
            ? (self::$resolver)($command)
            : ['', 0];

        self::$lastExit = $exit;

        $stdout = fopen('php://temp', 'w+b');
        fwrite($stdout, $body);
        rewind($stdout);

        $stderr = fopen('php://temp', 'w+b');

        if ($exit !== 0) {
            fwrite($stderr, "command failed\n");
        }

        rewind($stderr);

        // A plain resource stands in for the process handle. Nothing reads it
        // except close()/terminate(), which this class answers itself.
        return [fopen('php://temp', 'w+b'), $stdout, $stderr];
    }

    public function close($process): int
    {
        if (is_resource($process)) {
            fclose($process);
        }

        return self::$lastExit;
    }

    public function terminate($process): void
    {
        // Nothing to signal.
    }
}
