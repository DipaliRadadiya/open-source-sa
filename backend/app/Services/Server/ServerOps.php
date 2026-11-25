<?php

namespace App\Services\Server;

use App\Support\CommandRedactor;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\RuntimeException as SymfonyProcessException;

/**
 * Runs a local server command via the Process facade (array args, no shell interpolation) and
 * logs every execution to the `server-ops` channel. Never throws: the caller inspects the result
 * and raises its own translated exception. Secrets must never be passed in the command array.
 */
class ServerOps
{
    /**
     * How much of a stream to hold at once.
     *
     * This number, not the file size, is the memory cost of a download (1 MB x 100 downloads = 100
     * MB).
     */
    private const STREAM_CHUNK_BYTES = 1048576;

    /**
     * How much of a streamed command's stderr to keep for the log.
     *
     * Bounded: a command that prints megabytes of warnings must not bring back unbounded growth.
     */
    private const STREAM_STDERR_BYTES = 8192;

    /**
     * Injected rather than called directly so downloads stay testable:
     * `proc_open` is invisible to `Process::fake()`. See {@see CommandPipe}.
     */
    public function __construct(private CommandPipe $pipe = new CommandPipe) {}

    /**
     * @param  array<int, string>  $command
     * @param  array<string, mixed>  $context
     * @param  mixed  $input  Data piped to the command's stdin (e.g. `chpasswd`). Never logged; keep
     *                        secrets here, not in the command array. A **stream resource** is also
     *                        accepted and is how anything file-sized must be passed (a string holds
     *                        every byte in PHP memory). PHP has no `resource` type, hence `mixed`.
     * @param  string|null  $cwd  Working directory. Some tools (Nextcloud's `occ install`) refuse to
     *                            run from anywhere else, and there is no shell to `cd` in.
     * @param  array<string, string>  $env  Extra environment (e.g. DEBIAN_FRONTEND, without which apt
     *                                      prompts and hangs until the timeout). Child processes
     *                                      inherit it, so it is not for secrets.
     * @param  callable|null  $onOutput  Called with each chunk as it arrives, for slow commands someone
     *                                   is watching (an apt install). Kept here rather than in
     *                                   `stream()` so the transient retry, failure classification and
     *                                   ops-log entry stay. Called once per chunk *per attempt*: a
     *                                   retry replays from the start.
     * @param  array<int, int>  $expectedExitCodes  Exit codes that answer an expected negative probe.
     */
    public function run(array $command, array $context = [], int $timeout = 60, mixed $input = null, ?string $cwd = null, array $env = [], ?callable $onOutput = null, array $expectedExitCodes = [], ?int $retryAttempts = null, ?int $retryDelayMs = null, ?callable $onRetry = null): ServerOpsResult
    {
        $command = $this->elevate($command, $env);

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

        // A lock failure means the command refused before doing anything, so retrying is safe (the
        // holder is usually unattended-upgrades or an install about to finish).
        while ($attempts < $maxAttempts) {
            $attempts++;
            $ok = false;
            $exitCode = null;
            $stderr = '';
            $stdout = '';
            $result = null;
            $timedOut = false;

            try {
                $pending = Process::timeout($timeout);
                if ($input !== null) {
                    // A stream is consumed by the attempt that reads it: rewind before a retry, and if
                    // it is not seekable stop retrying rather than silently write an empty file.
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
                // Carried out to the caller, not left only in stderr: a command killed at its ceiling
                // and one that refused are different problems with different answers.
                $timedOut = true;

                // ORDER MATTERS: Laravel's ProcessTimedOutException extends Symfony's
                // RuntimeException, so with the catches swapped a timed-out command is reported as
                // a failed spawn and `timedOut` is never set.
            } catch (SymfonyProcessException $e) {
                // "Never throws" is relied on by every caller. A cwd the panel user cannot enter fails
                // inside `posix_spawn`, before the process exists. Catch the Process component's
                // RuntimeException (an unreadable and a missing directory throw different subclasses).
                $stderr = 'could not start the process: '.$e->getMessage();
            }

            if ($ok || $attempts >= $maxAttempts || ! $this->isTransient($stderr)) {
                break;
            }

            // Logged at each attempt: a command that only succeeded after waiting is worth seeing.
            Log::channel('server-ops')->warning('server operation busy, retrying', array_merge($context, [
                'reference' => $reference,
                'command' => $this->loggableCommand($command),
                'attempt' => $attempts,
                'of' => $maxAttempts,
                'stderr' => CommandRedactor::line($stderr),
            ]));

            if ($onRetry !== null) {
                $onRetry($attempts, $maxAttempts);
            }

            usleep($retryDelayMs * 1000);
        }

        // Some commands answer a question: `test -f` uses exit 1 for "no". Keep the unsuccessful
        // result for the caller, but keep an expected answer off the admin error dashboard.
        //
        // Never a refused sudo, which exits 1 as well: the panel could not ask at all, and the
        // dashboard is where that should be noticed.
        $denied = ! $ok && $this->isSudoDenied($command, $stderr);
        $expectedExit = ! $ok && ! $denied && $exitCode !== null && in_array($exitCode, $expectedExitCodes, true);

        Log::channel('server-ops')->{$ok || $expectedExit ? 'info' : 'error'}('server operation', array_merge($context, [
            // Without a message the dashboard falls back to "Server operation failed." for a stale
            // sudo grant, hiding the one useful fact: the panel may not run this binary.
            ...($denied ? ['message' => __('errors/server.sudo_denied')] : []),
            'reference' => $reference,
            'command' => $this->loggableCommand($command),
            'exit_code' => $exitCode,
            'expected_exit' => $expectedExit,
            'timed_out' => $timedOut,
            // Redacted like stdout: a failed CREATE USER repeats its IDENTIFIED BY '<password>'.
            'stderr' => CommandRedactor::line($stderr),
            // Only on failure, and only the tail (the reason comes last): many tools (artisan,
            // wp-cli, composer) report errors on stdout and leave stderr empty. Bounded because
            // some successful outputs are huge listings. Also kept on success when the caller asks
            // (`log_output`): a command can exit 0 having done nothing.
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
            // Set only after every retry is spent: the same message means "wait" while someone holds
            // the lock and "this is broken" once nobody does.
            staleLock: ! $ok && $this->isStaleLock($stderr),
            denied: $denied,
            timedOut: $timedOut,
            // Computed once here: callers that worked it out themselves got it wrong. See
            // ServerOpsResult::$answered.
            answered: $ok || ($expectedExit && trim($stderr) === ''),
        );
    }

    /**
     * sudo's default secure_path on Debian and Ubuntu.
     *
     * The panel account's own PATH lacks /usr/sbin (useradd, chpasswd, ...), so searching it reports
     * installed binaries as missing. sudo resolves binaries through secure_path.
     */
    private const SEARCH_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    /**
     * Is this binary installed? Asked **without** sudo, deliberately.
     *
     * sudo matches NOPASSWD rules against the resolved absolute path, so for a missing binary its
     * answer is "a password is required", indistinguishable from a stale grant. A failed elevated call
     * therefore says something about privileges, not presence. Ask presence here first.
     *
     * `command -v` rather than `is_executable()` on a guessed path, so the binary resolves the way
     * the real call will resolve it.
     */
    public function binaryExists(string $binary): bool
    {
        return Process::timeout(10)
            ->env(['PATH' => self::SEARCH_PATH])
            ->run(['sh', '-c', 'command -v '.escapeshellarg($binary)])
            ->successful();
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
     * `run()` retries a held lock on the short budget a passwd lock needs (3 x 1.5s). apt's contender
     * is `unattended-upgrades`, which holds the lock for minutes, so apt gets its own budget; raising
     * the generic one would make every busy account operation hang. No lock probe first: a check
     * followed by a command races, while apt takes the lock atomically.
     *
     * `DPkg::Lock::Timeout=0` is passed on purpose: install.sh leaves a drop-in with
     * `DPkg::Lock::Timeout "900"`, so apt would wait the full 900 s (the install timeout) and the
     * retry loop never runs. The command line beats the drop-in. The wait is then bounded by
     * `lock_attempts × lock_delay_ms`, and a lock still held fails as `apt_lock`, not `unknown`.
     *
     * @param  array<int, string>  $command
     * @param  array<string, mixed>  $context
     * @param  callable(int, int): void|null  $onWait  told each time the lock was busy
     */
    public function apt(array $command, array $context = [], int $timeout = 900, array $env = [], ?callable $onOutput = null, ?callable $onWait = null): ServerOpsResult
    {
        // Last, not first: apt reads options anywhere, and keeping the verb at [1] keeps the ops log
        // and the sudo grant check seeing `apt-get install ...`.
        if (($command[0] ?? null) === 'apt-get') {
            $command = [...$command, '-o', 'DPkg::Lock::Timeout=0'];
        }

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
     * How long {@see apt()} waits out a held lock before failing, in seconds.
     *
     * A job that runs apt must outlast this plus the install itself, or the worker kills it mid-wait.
     */
    public static function aptLockBudgetSeconds(): int
    {
        return intdiv(
            max(1, (int) config('server.apt.lock_attempts', 40)) * max(100, (int) config('server.apt.lock_delay_ms', 15000)),
            1000,
        );
    }

    /**
     * Runs a command and yields its stdout in chunks as it arrives.
     *
     * `run()` returns the whole output as a string, so peak memory is the output size: wrong for `cat`
     * on a 4 GB archive. Symfony's `Process` buffers the pipe, and a fast `cat` outruns a slow client
     * until PHP's memory limit is gone, after the headers are sent (the browser shows
     * ERR_INVALID_RESPONSE). `proc_open` with bounded reads leaves backpressure to the kernel: stop
     * reading and the pipe fills and `cat` blocks.
     *
     * No retry loop, unlike `run()`: a replay would corrupt a download whose earlier chunks were
     * already sent.
     *
     * @param  int  $idleSeconds  how long to wait for the *next* byte, not for the whole transfer (a
     *                            wall-clock limit cuts a legitimate slow download and never fires for
     *                            a client that went away mid-stream).
     * @param  array<int, string>  $command
     * @param  array<string, mixed>  $context
     * @return \Generator<int, string>
     */
    public function stream(array $command, array $context = [], int $idleSeconds = 120): \Generator
    {
        $command = $this->elevate($command);

        $reference = (string) Str::uuid();
        $startedAt = microtime(true);
        $bytes = 0;

        [$process, $stdout, $stderrPipe] = $this->pipe->open($command);

        if ($process === null || $stdout === null || $stderrPipe === null) {
            Log::channel('server-ops')->error('server operation stream', array_merge($context, [
                'reference' => $reference,
                'command' => $this->loggableCommand($command),
                'exit_code' => null,
                'stderr' => 'could not start the process',
                'bytes' => 0,
                'duration_ms' => 0,
                'actor_id' => Auth::id(),
            ]));

            return;
        }

        // stderr never blocks the transfer: a command writing a lot there while we ship stdout would
        // fill its own pipe and deadlock.
        stream_set_blocking($stderrPipe, false);

        // Non-blocking plus `stream_select`, not `stream_set_timeout`, which has no effect on a
        // `proc_open` pipe. Backpressure still works: it comes from not calling `fread`, not from
        // the handle's blocking mode.
        stream_set_blocking($stdout, false);

        $stderr = '';
        $timedOut = false;

        while (true) {
            $read = [$stdout];
            $write = null;
            $except = null;

            $ready = stream_select($read, $write, $except, $idleSeconds);

            if ($ready === false) {
                break;
            }

            // Nothing arrived within the idle window: the producer has stopped, not just slowed. A
            // wall-clock limit cannot tell those apart, hence per-byte rather than per-request.
            if ($ready === 0) {
                $timedOut = true;

                break;
            }

            $chunk = fread($stdout, self::STREAM_CHUNK_BYTES);

            if ($chunk === false || $chunk === '') {
                // Readable and empty means the writer closed. Readable with nothing yet is a spurious
                // wakeup: loop.
                if (feof($stdout)) {
                    break;
                }

                continue;
            }

            $bytes += strlen($chunk);

            // Drained as we go and bounded: a command printing megabytes of warnings must not become a
            // memory problem of another shape.
            if (strlen($stderr) < self::STREAM_STDERR_BYTES) {
                $stderr .= (string) fread($stderrPipe, 8192);
            }

            yield $chunk;
        }

        $stderr .= (string) stream_get_contents($stderrPipe, self::STREAM_STDERR_BYTES);

        // Kill before closing: `proc_close` waits for the child, so an abandoned stream (idle
        // producer, closed tab) would hold the request for the command's natural life.
        if ($timedOut) {
            $this->pipe->terminate($process);
        }

        fclose($stdout);
        fclose($stderrPipe);

        $exitCode = $this->pipe->close($process);
        $ok = $exitCode === 0 && ! $timedOut;

        // Logged after the fact: the headers went out with the first chunk, so the log is the only
        // record of a truncated download (hence the byte count).
        Log::channel('server-ops')->{$ok ? 'info' : 'error'}('server operation stream', array_merge($context, [
            'reference' => $reference,
            'command' => $this->loggableCommand($command),
            'exit_code' => $exitCode,
            'stderr' => $timedOut
                ? 'stream idle for '.$idleSeconds.'s; gave up after '.$bytes.' bytes'
                : CommandRedactor::line(trim($stderr)),
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
     * Redacted like the command line: an installer that echoes what it was given would otherwise
     * put a password in the log.
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

    /**
     * Whether stderr describes a "busy, nothing happened" failure.
     *
     * Only conditions where the command refused *before* changing anything qualify (the account
     * tools and apt take their lock first). A failure that might have half-completed must never be
     * retried.
     */
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
     * A lock file left by an interrupted `useradd` (`<file>.lock` link) blocks every account
     * operation until removed. It looks like "busy" and survives the retries, but waiting is useless.
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
     * Did sudo refuse the command before the binary ever ran?
     *
     * The command did not fail, it did not happen: the grant in /etc/sudoers.d does not cover this
     * binary, retrying never helps, and one command as root repairs every feature. The grant goes
     * stale by design (rewritten only by install.sh and the update's non-fatal `sync_privileges`
     * step), so every binary added since fails with prose about a password.
     *
     * Anchored to sudo's own output: only consulted for a command this class elevated, and the
     * phrases must be on a line prefixed `sudo:`, otherwise a tool that merely prints "a password is
     * required" would be reported as a privilege fault. The "not allowed to execute" and "not in the
     * sudoers file" refusals carry no prefix and are matched on their own shape.
     *
     * Kept in code, not `server.transient.*` config: this is sudo's fixed vocabulary, not a tuning
     * knob.
     *
     * @param  array<int, string>  $command  Already elevated.
     */
    private function isSudoDenied(array $command, string $stderr): bool
    {
        if ($stderr === '' || ($command[0] ?? null) !== 'sudo') {
            return false;
        }

        foreach (preg_split('/\R/', $stderr) ?: [] as $line) {
            $line = strtolower(trim($line));

            if (str_starts_with($line, 'sudo:') && (
                str_contains($line, 'password is required')
                || str_contains($line, 'no askpass program')
                || str_contains($line, 'a terminal is required')
            )) {
                return true;
            }

            if (str_contains($line, 'is not in the sudoers file')
                || preg_match('/\bis not allowed to execute\b/', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Put `sudo -n` in front of commands the panel may elevate.
     *
     * php-fpm runs unprivileged, so without this every useradd, systemctl, ufw and apt-get call fails
     * with "Permission denied". `-n` is deliberate: a missing grant must fail at once instead of
     * waiting for a password on a terminal that does not exist. Skipped when already root.
     *
     * `$env` is threaded through because sudo resets the environment (`env_reset`), silently dropping
     * variables set on the Process (PostgreSQL's `PGPASSFILE` was the one that bit). It is carried as
     * `sudo -n env VAR=value <command>` rather than `sudo -E`: exactly the variables asked for, `env`
     * is already in the allowlist, and each assignment is its own argv entry so a space in a value
     * cannot become a second argument.
     *
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     * @return array<int, string>
     */
    private function elevate(array $command, array $env = []): array
    {
        if ($command === [] || ! config('server.privilege.sudo', true)) {
            return $command;
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return $command;
        }

        // Match on the binary name only: sudo resolves bare names through secure_path and the sudoers
        // rule matches the resolved absolute path, so both spellings land on the same rule.
        $binary = basename($command[0]);

        // php-fpm{version} is a family of binaries (one per PHP version) that an exact-match list
        // cannot cover; install.sh's sudoers grant uses a /usr/sbin/php-fpm* wildcard too.
        $privileged = in_array($binary, (array) config('server.privilege.binaries', []), true)
            || str_starts_with($binary, 'php-fpm');

        if (! $privileged) {
            return $command;
        }

        if ($env === []) {
            return array_merge(['sudo', '-n'], $command);
        }

        $assignments = [];

        foreach ($env as $name => $value) {
            $assignments[] = $name.'='.$value;
        }

        return array_merge(['sudo', '-n', 'env'], $assignments, $command);
    }
}
