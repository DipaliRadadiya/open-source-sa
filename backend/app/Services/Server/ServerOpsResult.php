<?php

namespace App\Services\Server;

use Illuminate\Contracts\Process\ProcessResult;

/**
 * Outcome of a server operation: whether it succeeded, a reference id the
 * user can quote to support (correlates with the server-ops log), and the
 * underlying process result when available.
 */
class ServerOpsResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $reference,
        public readonly ?ProcessResult $result = null,
        /**
         * The failure was a lock/busy condition that survived every retry —
         * the system was occupied, not misconfigured. Callers can use this to
         * tell the operator to try again rather than reporting a hard error
         * for something that will work by itself in a minute.
         */
        public readonly bool $busy = false,
        /**
         * The lock survived every retry with nobody holding it — an interrupted
         * tool left a lock file behind. Reported apart from `busy` because the
         * advice is the opposite: waiting will never help, the file has to go.
         * `panel:doctor` names the exact files.
         */
        public readonly bool $staleLock = false,
    ) {}

    public function failed(): bool
    {
        return ! $this->ok;
    }

    public function output(): string
    {
        return $this->result?->output() ?? '';
    }

    /**
     * Standard error, for the handful of tools that report their *answer*
     * there rather than a complaint — `apt-check` prints its counts to stderr
     * and nothing to stdout. Reading only `output()` from one of those looks
     * exactly like a successful empty result.
     */
    public function errorOutput(): string
    {
        return $this->result?->errorOutput() ?? '';
    }
}
