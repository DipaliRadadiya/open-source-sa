<?php

namespace App\Services\Server\Settings;

use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * Installs the security updates that are waiting, now.
 *
 * Runs **unattended-upgrades' own binary**, not `apt-get upgrade`. The button
 * sits directly under a toggle that promises "security patches only" and a count
 * produced by `apt-check`, so it has to mean *run the policy you already
 * configured* — `apt-get upgrade` would upgrade everything on the box, which is
 * a different and larger promise than the card makes.
 *
 * It also works with the automation switched off. The enable flags in the
 * drop-in gate apt's **timer**; the binary reads `Allowed-Origins` regardless.
 * So "I patch manually, when I choose" is a posture this supports rather than a
 * thing the UI has to apologise for.
 *
 * Service restarts and `/var/run/reboot-required` are unattended-upgrades' own
 * business — it runs needrestart itself. The panel does not reboot here; the
 * tracker records that the box now wants one and the card says so.
 */
class SecurityUpdateRunner
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Is the tool even here?
     *
     * Asked before a run is queued, so an operator gets a straight answer in the
     * response instead of a job that fails a minute later for a reason nothing
     * on the screen could explain. The package is standard on Ubuntu Server but
     * a minimal image or a container may not carry it.
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
        // `apt()` rather than `run()`: it waits out the dpkg lock — forty
        // attempts, fifteen seconds apart — which is the common case here
        // rather than an edge one, because apt's own timer may already be
        // running the very thing being asked for.
        //
        // A retry replays the command from the start and therefore replays its
        // output. That is harmless: the buffer reading this is a tail, so a
        // second attempt simply overwrites the first attempt's narration
        // instead of being appended to it.
        return $this->serverOps->apt(
            [$this->binary(), '-v'],
            ['feature' => 'setting', 'group' => 'updates', 'op' => 'security_update'],
            timeout: (int) config('server.security_updates.timeout', 1800),
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
            onOutput: $onOutput,
        );
    }

    /**
     * Why it failed, as a code the frontend can word.
     *
     * Classified here, where the output still exists — the same rule
     * `PhpRuntime::install()` follows. Past this point only the code travels,
     * so a reason that cannot be derived now cannot be derived at all.
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
            // dpkg's own words when a maintainer script exits non-zero. The
            // most actionable failure there is, and the one whose detail lives
            // in the dpkg log rather than this output.
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
