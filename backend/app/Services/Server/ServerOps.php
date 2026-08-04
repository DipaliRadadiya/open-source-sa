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
     * @param  string|null  $input  Data piped to the command's stdin (e.g. for
     *                              `chpasswd`). Never logged — keep secrets here,
     *                              not in the command array.
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
    public function run(array $command, array $context = [], int $timeout = 60, ?string $input = null, ?string $cwd = null, array $env = []): ServerOpsResult
    {
        $command = $this->elevate($command);

        $reference = (string) Str::uuid();
        $startedAt = microtime(true);

        $ok = false;
        $exitCode = null;
        $stderr = '';
        $result = null;

        try {
            $pending = Process::timeout($timeout);
            if ($input !== null) {
                $pending = $pending->input($input);
            }
            if ($cwd !== null) {
                $pending = $pending->path($cwd);
            }
            if ($env !== []) {
                $pending = $pending->env($env);
            }
            $result = $pending->run($command);
            $ok = $result->successful();
            $exitCode = $result->exitCode();
            $stderr = $result->errorOutput();
        } catch (ProcessTimedOutException) {
            $stderr = 'process timed out';
        }

        Log::channel('server-ops')->{$ok ? 'info' : 'error'}('server operation', array_merge($context, [
            'reference' => $reference,
            'command' => implode(' ', $command),
            'exit_code' => $exitCode,
            'stderr' => $stderr,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'actor_id' => Auth::id(),
        ]));

        return new ServerOpsResult($ok, $reference, $result);
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

        if (! in_array($binary, (array) config('server.privilege.binaries', []), true)) {
            return $command;
        }

        return array_merge(['sudo', '-n'], $command);
    }
}
