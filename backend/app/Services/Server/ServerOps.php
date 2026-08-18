<?php

namespace App\Services\Server;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Runs a local server command via the Process facade (array args — no shell
 * interpolation, so no injection) and records a structured entry to the
 * `server-ops` log channel for every execution. Never throws — the caller
 * inspects the result and raises its own translated, feature-namespaced
 * exception on failure. Secrets must never be passed in the command array.
 */
class ServerOps
{
    /**
     * @param  array<int, string>  $command
     * @param  array<string, mixed>  $context
     * @param  mixed  $input  Data piped to the command's stdin (e.g. for
     *                        `chpasswd`). Never logged — keep secrets here,
     *                        not in the command array.
     *
     *                        A **stream resource** is also accepted, and is how
     *                        anything file-sized must be passed: Symfony reads
     *                        it incrementally, so memory stays at the pipe
     *                        buffer instead of the whole payload. Passing a
     *                        string means holding every byte in PHP memory —
     *                        fine for a password, fatal for an upload. There is
     *                        no `resource` type declaration in PHP, hence
     *                        `mixed`.
     * @param  string|null  $cwd  Working directory. Some tools refuse to run
     *                            from anywhere else — Nextcloud's `occ install`
     *                            fails outright unless it is run from its own
     *                            root — and there is no shell here to `cd` in.
     * @param  array<string, string>  $env  Extra environment. apt will not run
     *                                      unattended without
     *                                      DEBIAN_FRONTEND, and a prompt with
     *                                      nobody to answer it hangs until the
     *                                      timeout. Note this is visible only
     *                                      to the process owner, unlike a
     *                                      command line — but it is still not
     *                                      the place for secrets, since a
     *                                      child process inherits it.
     */
    /**
     * @param  callable|null  $onOutput  Called with each chunk as it arrives,
     *                                   for a command slow enough that someone
     *                                   is watching it — an apt install runs
     *                                   for minutes and says nothing useful
     *                                   until it is over.
     *
     *                                   Deliberately here rather than in
     *                                   `stream()`: this keeps the transient
     *                                   retry, the failure classification and
     *                                   the ops-log entry, all of which
     *                                   streaming gives up. An apt install
     *                                   losing its lock retry to gain a
     *                                   progress bar would be a poor trade.
     *
     *                                   Called once per chunk *per attempt*;
     *                                   a retry replays from the start, so a
     *                                   consumer that accumulates should
     *                                   expect to see the beginning twice.
     */
    public function run(array $command, array $context = [], int $timeout = 60, mixed $input = null, ?string $cwd = null, array $env = [], ?callable $onOutput = null): ServerOpsResult
    {
        $command = $this->elevate($command);

        $reference = (string) Str::uuid();
        $startedAt = microtime(true);

        $ok = false;
        $exitCode = null;
        $stderr = '';
        $result = null;
        $attempts = 0;

        $maxAttempts = max(1, (int) config('server.transient.attempts', 1));

        // A lock failure means the command refused before doing anything, so
        // trying again is safe and usually works: the holder is normally
        // unattended-upgrades or an install that is seconds from finishing.
        // Without this the operator gets a hard error for something that
        // would have succeeded on its own.
        while ($attempts < $maxAttempts) {
            $attempts++;
            $ok = false;
            $exitCode = null;
            $stderr = '';
            $result = null;

            try {
                $pending = Process::timeout($timeout);
                if ($input !== null) {
                    // A stream is consumed by the attempt that reads it, so a
                    // retry would pipe nothing and "succeed" at writing an
                    // empty file. Rewind first; if the stream is not seekable
                    // there is nothing to replay, so refuse to retry rather
                    // than silently truncate.
                    if (is_resource($input) && $attempts > 1) {
                        if (! @rewind($input)) {
                            break;
                        }
                    }

                    $pending = $pending->input($input);
                }
                if ($cwd !== null) {
                    $pending = $pending->path($cwd);
                }
                if ($env !== []) {
                    $pending = $pending->env($env);
                }
                $result = $onOutput === null
                    ? $pending->run($command)
                    : $pending->run($command, fn (string $type, string $chunk) => $onOutput($chunk));
                $ok = $result->successful();
                $exitCode = $result->exitCode();
                $stderr = $result->errorOutput();
            } catch (ProcessTimedOutException) {
                $stderr = 'process timed out';
            }

            if ($ok || $attempts >= $maxAttempts || ! $this->isTransient($stderr)) {
                break;
            }

            // Logged at each attempt: a command that eventually succeeded
            // after waiting is worth seeing, and a server where this happens
            // constantly has a problem of its own.
            Log::channel('server-ops')->warning('server operation busy, retrying', array_merge($context, [
                'reference' => $reference,
                'command' => $this->loggableCommand($command),
                'attempt' => $attempts,
                'of' => $maxAttempts,
                'stderr' => $stderr,
            ]));

            usleep(max(0, (int) config('server.transient.delay_ms', 1500)) * 1000);
        }

        Log::channel('server-ops')->{$ok ? 'info' : 'error'}('server operation', array_merge($context, [
            'reference' => $reference,
            'command' => $this->loggableCommand($command),
            'exit_code' => $exitCode,
            'stderr' => $stderr,
            'attempts' => $attempts,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'actor_id' => Auth::id(),
        ]));

        return new ServerOpsResult(
            $ok,
            $reference,
            $result,
            busy: ! $ok && $this->isTransient($stderr),
            // Distinguished only after every retry is spent: the same message
            // means "wait" while someone holds the lock and "this is broken"
            // once nobody does.
            staleLock: ! $ok && $this->isStaleLock($stderr),
        );
    }

    /**
     * Whether stderr describes a "busy, nothing happened" failure.
     *
     * Only conditions where the command refused *before* changing anything
     * qualify — the account tools and apt both take their lock first, so a
     * lock failure guarantees no partial work to worry about. A failure that
     * might have half-completed must never be retried.
     */
    /**
     * Runs a command and yields its stdout in chunks as it arrives.
     *
     * `run()` waits for the command to finish and hands back the whole of its
     * output as a string, which is right for the commands that produce a line
     * or two and wrong for `cat` on a 4 GB archive: peak memory is the size of
     * the output. Here nothing accumulates — each chunk is yielded and
     * dropped, so a 40 GB file costs the same resident memory as a 40 KB one.
     *
     * No retry loop, unlike `run()`. A transient-lock retry replays the whole
     * command, and the caller has already sent the earlier chunks to the
     * client — replaying would corrupt the download rather than recover it.
     *
     * @param  array<int, string>  $command
     * @param  array<string, mixed>  $context
     * @return \Generator<int, string>
     */
    public function stream(array $command, array $context = [], int $timeout = 3600): \Generator
    {
        $command = $this->elevate($command);

        $reference = (string) Str::uuid();
        $startedAt = microtime(true);
        $bytes = 0;

        $process = Process::timeout($timeout)->start($command);

        while ($process->running()) {
            $chunk = $process->latestOutput();

            if ($chunk !== '') {
                $bytes += strlen($chunk);
                yield $chunk;
            }
        }

        // Whatever landed between the last poll and the process exiting.
        $chunk = $process->latestOutput();

        if ($chunk !== '') {
            $bytes += strlen($chunk);
            yield $chunk;
        }

        $result = $process->wait();
        $ok = $result->successful();

        // Logged after the fact, and the failure cannot be turned into an
        // error response: the headers went out with the first chunk. The log
        // is the only place a truncated download is recorded, which is exactly
        // why it records the byte count.
        Log::channel('server-ops')->{$ok ? 'info' : 'error'}('server operation stream', array_merge($context, [
            'reference' => $reference,
            'command' => $this->loggableCommand($command),
            'exit_code' => $result->exitCode(),
            'stderr' => $result->errorOutput(),
            'bytes' => $bytes,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'actor_id' => Auth::id(),
        ]));
    }

    /**
     * Render a command for logs without persisting command-line secrets.
     *
     * Some third-party installers accept a password only as an argv option.
     * The process must receive that original option, but retaining it in the
     * server-ops log after the process exits turns a brief exposure into a
     * permanent one. Support both --option=value and --option value forms.
     *
     * @param  array<int, string>  $command
     */
    private function loggableCommand(array $command): string
    {
        $arguments = [];
        $redactNext = false;

        foreach ($command as $argument) {
            if ($redactNext) {
                $arguments[] = '[REDACTED]';
                $redactNext = false;

                continue;
            }

            if (preg_match('/^(--[a-z0-9_-]*(?:password|secret|token|api[-_]?key|private[-_]?key)[a-z0-9_-]*)(?:=(.*))?$/i', $argument, $matches)) {
                $arguments[] = isset($matches[2]) ? $matches[1].'=[REDACTED]' : $matches[1];
                $redactNext = ! isset($matches[2]);

                continue;
            }

            $arguments[] = $argument;
        }

        return implode(' ', $arguments);
    }

    private function isTransient(string $stderr): bool
    {
        if ($stderr === '') {
            return false;
        }

        $haystack = strtolower($stderr);

        foreach ((array) config('server.transient.patterns', []) as $pattern) {
            if (str_contains($haystack, strtolower((string) $pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the failure is a lock that outlived its holder.
     *
     * A lock file left by an interrupted useradd blocks every account
     * operation permanently — `useradd` links `<file>.<pid>` to `<file>.lock`
     * and refuses while the link exists, and nothing ever cleans it up. It
     * looks exactly like "busy", so it survives the retries and then needs
     * saying differently: waiting is useless, the file must be removed.
     */
    private function isStaleLock(string $stderr): bool
    {
        if ($stderr === '') {
            return false;
        }

        $haystack = strtolower($stderr);

        foreach ((array) config('server.transient.stale_lock_patterns', []) as $pattern) {
            if (str_contains($haystack, strtolower((string) $pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Put `sudo -n` in front of commands that cannot work as the panel user.
     *
     * This is the whole reason the sudoers file install.sh writes has any
     * effect: php-fpm runs unprivileged, so without this every useradd,
     * systemctl, ufw and apt-get call fails with "Permission denied" — the
     * panel installs cleanly and then cannot do its job.
     *
     * `-n` is deliberate. A missing grant must fail immediately and visibly;
     * without it sudo waits for a password on a terminal that does not exist
     * and the operation hangs until the timeout, reported as something else
     * entirely.
     *
     * Escalation is skipped when already root (an operator running artisan
     * by hand, or a container that runs as root) — sudo would work, but
     * requiring it to be installed to run as root is a gratuitous dependency.
     *
     * @param  array<int, string>  $command
     * @return array<int, string>
     */
    private function elevate(array $command): array
    {
        if ($command === [] || ! config('server.privilege.sudo', true)) {
            return $command;
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return $command;
        }

        // Match on the binary name only. Callers pass bare names ('useradd'),
        // sudo resolves them through secure_path, and the sudoers rule matches
        // the resolved absolute path — so both spellings land on the same rule.
        $binary = basename($command[0]);

        // php-fpm{version} (PoolManager's `php-fpm8.4 -t` config test) is a
        // whole family of binaries, one per installed PHP version, which an
        // exact-match list can't cover without editing code every time a
        // version is added or removed. install.sh's own sudoers grant uses a
        // /usr/sbin/php-fpm* wildcard for the same reason — this mirrors it.
        $privileged = in_array($binary, (array) config('server.privilege.binaries', []), true)
            || str_starts_with($binary, 'php-fpm');

        if (! $privileged) {
            return $command;
        }

        return array_merge(['sudo', '-n'], $command);
    }
}
