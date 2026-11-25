<?php

namespace App\Services\Server;

use Illuminate\Contracts\Process\ProcessResult;

/**
 * Outcome of a server operation: success, a reference id the user can quote to support
 * (correlates with the server-ops log), and the process result when available.
 */
class ServerOpsResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $reference,
        public readonly ?ProcessResult $result = null,
        /** A lock/busy condition survived every retry: occupied, not misconfigured. Try again later. */
        public readonly bool $busy = false,
        /**
         * The lock survived every retry with nobody holding it (an interrupted tool left a lock
         * file). Apart from `busy` because waiting never helps; `panel:doctor` names the files.
         */
        public readonly bool $staleLock = false,
        /**
         * sudo refused before the binary ran: the panel's grant does not cover this command.
         *
         * Not a server fault: nothing ran. `artisan panel:sudoers` rewrites the grant. Callers
         * must not read it as an answer; see `answered`.
         */
        public readonly bool $denied = false,
        /**
         * The command ran and gave its own answer.
         *
         * `ok` conflates two cases: `test -f` exits 1 for "not there", and sudo exits 1 for "you
         * may not run this" before the binary runs. Reading `ok` as the answer reports "no" for
         * a question never asked (and lets an existence guard pass before `mv -f`).
         *
         * True when the command succeeded, or exited with a code the caller declared a real
         * answer AND printed nothing on stderr (`test`, `grep` are silent on a plain "no").
         *
         * Read this FIRST: a guard must refuse on "could not find out", a screen should say so,
         * a diagnostic should warn.
         */
        public readonly bool $answered = false,
        /**
         * The command was killed at its timeout.
         *
         * Not a plain failure: the work did not fit the time allowed, so the answer is a smaller
         * job, not a retry.
         *
         * It also implies debris (a killed `tar` already created its output file): a caller that
         * writes to a path must clean up before rethrowing, or the retry fails on "already exists".
         */
        public readonly bool $timedOut = false,
    ) {}

    public function failed(): bool
    {
        return ! $this->ok;
    }

    /**
     * The exit code, for tools that use different non-zero codes (`openlitespeed -t` exits 1 for
     * warnings and 2 for errors).
     *
     * Null when the process never ran (timeout, sudo refusal); that is not an exit code of 0.
     */
    public function exitCode(): ?int
    {
        return $this->result?->exitCode();
    }

    public function output(): string
    {
        return $this->result?->output() ?? '';
    }

    /**
     * Standard error, for tools that report their *answer* there (`apt-check` prints its counts
     * to stderr), where `output()` alone looks like an empty success.
     */
    public function errorOutput(): string
    {
        return $this->result?->errorOutput() ?? '';
    }
}
