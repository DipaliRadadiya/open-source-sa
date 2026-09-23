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
        /**
         * sudo refused before the binary ran — the panel's grant does not
         * cover this command.
         *
         * Apart from `busy` and `staleLock` because it is not a fault on the
         * server at all: nothing is locked, nothing is broken, and nothing ran.
         * The panel is missing a line in /etc/sudoers.d, which `artisan
         * panel:sudoers` writes in full.
         *
         * A caller that reads only `ok` reports this as the operation failing,
         * which is how a stale grant gets diagnosed as a broken feature. A
         * caller that reads it as an answer does worse — see `answered`.
         */
        public readonly bool $denied = false,
        /**
         * The command ran and gave its own answer.
         *
         * `ok` is two questions collapsed into one: `test -f` exits 1 for "the
         * file is not there", and sudo exits 1 for "you may not run this" —
         * before the binary runs at all. Callers that read `ok` as the answer
         * therefore report "no" for a question that was never asked, which is
         * how the panel told a user their `.env` did not exist while they were
         * reading it in the file manager, and how an upload's
         * does-this-file-exist guard lets `mv -f` destroy a file.
         *
         * True when the command succeeded, or when it exited with a code the
         * caller declared as a real answer AND printed nothing on stderr —
         * `test`, `grep` and friends are silent when the answer is simply no,
         * so anything on stderr came from whatever refused to run them.
         *
         * A caller reads this FIRST and decides what "I could not find out"
         * means for it: a guard must refuse, a screen should say so, and a
         * diagnostic should warn rather than assert.
         */
        public readonly bool $answered = false,
        /**
         * The command was killed at its timeout rather than finishing.
         *
         * Separated from an ordinary failure because the advice is different
         * and specific: nothing is broken, the work did not fit in the time
         * allowed, and the answer is a smaller job — not a retry, which will
         * take exactly as long and fail exactly the same way.
         *
         * It also implies debris. A killed `tar` has already created its
         * output file, so a caller that writes to a path must clean up before
         * rethrowing, or the retry fails on "something already exists there"
         * and reports that as the problem. That is precisely how a 110 GB
         * compress was reported as a naming collision.
         */
        public readonly bool $timedOut = false,
    ) {}

    public function failed(): bool
    {
        return ! $this->ok;
    }

    /**
     * The exit code, for the few callers whose tool says different things with
     * different non-zero codes rather than just "it went wrong".
     *
     * `openlitespeed -t` is the one that forced this: it exits 1 for warnings
     * and 2 for errors, so reading only `failed()` there means refusing to
     * write a site over a warning. Null when the process never ran — a timeout
     * or a sudo refusal — which is not the same as an exit code of 0 and must
     * not be read as one.
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
