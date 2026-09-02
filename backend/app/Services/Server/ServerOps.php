<?php

namespace App\Services\Server;

use App\Support\CommandRedactor;
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
     * @param  array<int, int>  $expectedExitCodes  Exit codes that answer an
     *                                              expected negative probe.
     */
    public function run(array $command, array $context = [], int $timeout = 60, mixed $input = null, ?string $cwd = null, array $env = [], ?callable $onOutput = null, array $expectedExitCodes = [], ?int $retryAttempts = null, ?int $retryDelayMs = null, ?callable $onRetry = null): ServerOpsResult
    {
        $command = $this->elevate($command);

        $reference = (string) Str::uuid();
        $startedAt = microtime(true);

        $ok = false;
        $exitCode = null;
        $stderr = '';
        $stdout = '';
        $result = null;
        $attempts = 0;

        $maxAttempts = max(1, $retryAttempts ?? (int) config('server.transient.attempts', 1));
        $retryDelayMs = max(0, $retryDelayMs ?? (int) config('server.transient.delay_ms', 1500));

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
            $stdout = '';
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
                $stdout = $result->output();
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

            if ($onRetry !== null) {
                $onRetry($attempts, $maxAttempts);
            }

            usleep($retryDelayMs * 1000);
        }

        // Some commands answer a question rather than perform an operation:
        // `test -f` uses exit 1 for "no", which is useful state rather than a
        // failed server command. Keep the unsuccessful result for the caller,
        // but do not put an expected answer on the admin error dashboard.
        $expectedExit = ! $ok && $exitCode !== null && in_array($exitCode, $expectedExitCodes, true);

        Log::channel('server-ops')->{$ok || $expectedExit ? 'info' : 'error'}('server operation', array_merge($context, [
            'reference' => $reference,
            'command' => $this->loggableCommand($command),
            'exit_code' => $exitCode,
            'expected_exit' => $expectedExit,
            'stderr' => $stderr,
            // Only on failure, and only the tail. Plenty of the tools the
            // panel drives report their errors on stdout and leave stderr
            // empty — `artisan` does, and so do wp-cli and composer — so a
            // failed operation was logged as `"stderr":""` beside a non-zero
            // exit: proof that something broke, and nothing about what. That
            // is the same blind spot the size-measurement job had.
            //
            // The tail rather than the head, because a command that printed
            // progress before dying puts the reason last. Bounded because the
            // successful path of some of these is a 90 MB file listing, and a
            // log nobody can open is its own kind of missing.
            // Kept on success too when the caller asks. A command that exits 0
            // having done nothing is not hypothetical: PrestaShop's installer
            // returned success in half a second, wrote no configuration, and
            // the only record of why was on a stdout nobody kept. Failure-only
            // capture answers "what went wrong" and cannot answer "why did
            // nothing happen".
            'stdout' => $ok && ! ($context['log_output'] ?? false)
                ? null
                : $this->loggableOutput($stdout),
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
            // Computed here, once, because every caller that worked it out for
            // itself got it wrong the same way. See ServerOpsResult::$answered.
            answered: $ok || ($expectedExit && trim($stderr) === ''),
        );
    }

    /**
     * Run a boolean existence probe. Exit 1 means "not found", so it remains
     * a false result for the caller while being logged as an expected answer.
     * Timeouts and every other exit code remain genuine operation failures.
     *
     * @param  array<int, string>  $command
     * @param  array<string, mixed>  $context
     */
    public function probe(array $command, array $context = [], int $timeout = 60): ServerOpsResult
    {
        return $this->run($command, $context, $timeout, expectedExitCodes: [1]);
    }

    /**
     * An apt command, with a wait long enough for the holder apt actually has.
     *
     * `run()` already retries a held lock — `could not get lock` is one of the
     * transient patterns — but on the budget a *passwd* lock needs: three
     * attempts, 1.5s apart, because the thing holding that one is a competing
     * `useradd` that finishes in seconds.
     *
     * apt's contender is different in kind. A server that booted minutes ago is
     * running `unattended-upgrades`, which holds the lock for **minutes**. Four
     * and a half seconds of retries expire long before it lets go, and the user
     * gets a hard failure for something that would have worked on its own —
     * which is exactly what happened: install MongoDB on a fresh box, fail,
     * retry ten minutes later, succeed.
     *
     * So the budget is apt's own, not the generic one. Raising the generic
     * numbers instead would make every busy account operation hang for minutes,
     * where failing fast is right.
     *
     * Deliberately *not* a lock probe before the command. install.sh uses one
     * because it also wants to explain the delay, but a probe is a check
     * followed by a command, and anything can take the lock in between. apt
     * takes it atomically, so letting apt contend and retrying is the version
     * with no race in it.
     *
     * @param  array<int, string>  $command
     * @param  array<string, mixed>  $context
     * @param  callable(int, int): void|null  $onWait  told each time the lock was busy
     */
    public function apt(array $command, array $context = [], int $timeout = 900, array $env = [], ?callable $onOutput = null, ?callable $onWait = null): ServerOpsResult
    {
        return $this->run(
            $command,
            $context,
            $timeout,
            env: array_merge(['DEBIAN_FRONTEND' => 'noninteractive'], $env),
            onOutput: $onOutput,
            retryAttempts: max(1, (int) config('server.apt.lock_attempts', 40)),
            retryDelayMs: max(100, (int) config('server.apt.lock_delay_ms', 15000)),
            onRetry: $onWait,
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
     * @param  array<int, string>  $command
     */
    private function loggableCommand(array $command): string
    {
        return CommandRedactor::arguments($command);
    }

    /**
     * The tail of a failed command's stdout, bounded.
     *
     * Redacted through the same rules the command line is: an installer that
     * echoes back what it was given would otherwise put a password in the log
     * that the argv redaction was written to keep out of it.
     */
    private function loggableOutput(string $output): ?string
    {
        $output = trim($output);

        if ($output === '') {
            return null;
        }

        $limit = max(0, (int) config('server.log_output_limit', 4000));

        if (mb_strlen($output) > $limit) {
            $output = '…'.mb_substr($output, -$limit);
        }

        return CommandRedactor::line($output);
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
