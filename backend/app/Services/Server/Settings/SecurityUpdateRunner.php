<?php

namespace App\Services\Server\Settings;

use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * Installs the waiting security updates now.
 *
 * Runs unattended-upgrades' own binary, not `apt-get upgrade`: the button promises "security
 * patches only", and `apt-get upgrade` would upgrade everything. It works with automation off too:
 * the enable flags gate apt's timer, while the binary always reads `Allowed-Origins`.
 *
 * Service restarts and `/var/run/reboot-required` are unattended-upgrades' business; the panel
 * never reboots here, it only records that a reboot is wanted.
 */
class SecurityUpdateRunner
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Is the tool installed? Asked before queueing, so a minimal image or container without the
     * package fails in the response instead of in the job.
     */
    public function available(): bool
    {
        return is_file($this->binary());
    }

    /**
     * @param  callable(string):void|null  $onOutput  called per chunk
     */
    public function run(?callable $onOutput = null): ServerOpsResult
    {
        // `apt()` rather than `run()`: it waits out the dpkg lock (40 attempts, 15 s apart), which
        // is common here because apt's own timer may be running the same thing.
        // A retry replays the output; harmless, the reading buffer is a tail.
        return $this->serverOps->apt(
            [$this->binary(), '-v'],
            ['feature' => 'setting', 'group' => 'updates', 'op' => 'security_update'],
            timeout: (int) config('server.security_updates.timeout', 1800),
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
            onOutput: $onOutput,
        );
    }

    /**
     * Why it failed, as a code the frontend can word. Classified here because only the code
     * travels past this point (same rule as `PhpRuntime::install()`).
     */
    public function classify(ServerOpsResult $result, string $output): string
    {
        if ($result->denied) {
            return 'denied';
        }

        if ($result->staleLock) {
            return 'stale_lock';
        }

        if ($result->busy) {
            return 'locked';
        }

        $haystack = strtolower($output.' '.($result->result?->errorOutput() ?? ''));

        return match (true) {
            str_contains($haystack, 'process timed out') => 'timeout',
            // dpkg's words when a maintainer script exits non-zero; detail is in the dpkg log.
            str_contains($haystack, 'dpkg: error processing') => 'dpkg',
            str_contains($haystack, 'sub-process /usr/bin/dpkg returned an error') => 'dpkg',
            str_contains($haystack, 'could not resolve') => 'fetch',
            str_contains($haystack, 'temporary failure resolving') => 'fetch',
            str_contains($haystack, 'failed to fetch') => 'fetch',
            str_contains($haystack, 'no space left on device') => 'disk_full',
            str_contains($haystack, 'unmet dependencies') => 'dependencies',
            str_contains($haystack, 'held broken packages') => 'dependencies',
            default => 'unknown',
        };
    }

    private function binary(): string
    {
        return (string) config('server.security_updates.binary', '/usr/bin/unattended-upgrade');
    }
}
