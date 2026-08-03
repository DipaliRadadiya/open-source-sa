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
