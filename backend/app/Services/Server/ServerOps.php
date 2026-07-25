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
     */
    public function run(array $command, array $context = [], int $timeout = 60, ?string $input = null): ServerOpsResult
    {
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
}
