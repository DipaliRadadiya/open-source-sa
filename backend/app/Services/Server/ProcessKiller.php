<?php

namespace App\Services\Server;

use App\Exceptions\Server\Process\ProcessKillException;

/**
 * Signals a running process, subject to a short list of refusals.
 *
 * An endpoint that signals arbitrary PIDs is a way to take a server down from
 * a web form, so the guards are the substance of this class rather than a
 * wrapper around `kill`. What it does *not* do is try to second-guess the
 * administrator: whoever holds `dashboard:manage` could run `kill` over SSH,
 * so the panel is saving them a login, not granting them a new power. The
 * refusals below are the cases where the click almost certainly does not mean
 * what the user thinks it means.
 */
class ProcessKiller
{
    /**
     * TERM asks a process to shut down and lets it flush and close files.
     * KILL gives it no such chance, which is why it is not the default —
     * a database interrupted mid-write is a worse outcome than a process
     * that takes a moment to exit.
     *
     * @var array<int, string>
     */
    public const SIGNALS = ['TERM', 'KILL'];

    public function __construct(private ServerOps $serverOps) {}

    /**
     * @return array{pid: int, command: string, user: string, signal: string}
     */
    public function kill(int $pid, string $signal): array
    {
        $process = $this->inspect($pid);

        if ($process === null) {
            // Not "already dead, job done": PIDs are recycled, so a stale PID
            // may now belong to something else entirely.
            throw ProcessKillException::notFound();
        }

        $this->guard($pid, $process);

        $result = $this->serverOps->run(
            ['kill', "-{$signal}", (string) $pid],
            ['feature' => 'process', 'op' => 'kill', 'pid' => $pid, 'signal' => $signal],
        );

        if ($result->failed()) {
            throw ProcessKillException::failed($result->reference);
        }

        return [...$process, 'pid' => $pid, 'signal' => $signal];
    }

    /**
     * Live details for a PID, or null when it isn't running.
     *
     * Deliberately re-read at kill time rather than trusted from the request:
     * between the table rendering and the click, the process may have exited
     * and its PID been handed to something else.
     *
     * @return array{command: string, user: string, ppid: int}|null
     */
    public function inspect(int $pid): ?array
    {
        $result = $this->serverOps->run(
            ['ps', '-o', 'comm=,user=,ppid=', '-p', (string) $pid],
            ['feature' => 'process', 'op' => 'inspect', 'pid' => $pid],
        );

        $line = trim($result->output());

        if (! $result->ok || $line === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $line);

        return [
            'command' => (string) ($parts[0] ?? ''),
            'user' => (string) ($parts[1] ?? ''),
            'ppid' => (int) ($parts[2] ?? 0),
        ];
    }

    /**
     * @param  array{command: string, user: string, ppid: int}  $process
     */
    private function guard(int $pid, array $process): void
    {
        // PID 1 is the init system. Killing it panics the kernel — there is no
        // circumstance in which this is the intent.
        if ($pid === 1) {
            throw ProcessKillException::protectedProcess();
        }

        // Kernel threads (children of kthreadd, PID 2) aren't processes in any
        // sense the user means, and don't respond to signals.
        if ($pid === 2 || $process['ppid'] === 2) {
            throw ProcessKillException::kernelThread();
        }

        // Killing our own worker kills the request doing the killing; killing
        // the master takes the panel offline and with it the way back in.
        if ($pid === getmypid() || $pid === posix_getppid()) {
            throw ProcessKillException::self();
        }

        if ($this->belongsToProtectedService($process['command'])) {
            // These already can't be stopped from the Services screen. A PID
            // is not a way around that decision.
            throw ProcessKillException::protectedProcess();
        }
    }

    private function belongsToProtectedService(string $command): bool
    {
        foreach (config('server.protected_services', []) as $unit) {
            if ($this->letters($command) === $this->letters((string) $unit)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A name reduced to its letters.
     *
     * The unit and the process it runs are not spelled the same: systemd
     * calls it `php8.4-fpm` while `ps` reports `php-fpm8.4`. Comparing the
     * strings, or their prefixes, misses that — and a missed match here means
     * the panel lets you kill its own PHP.
     */
    private function letters(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z]/', '', $name));
    }
}
