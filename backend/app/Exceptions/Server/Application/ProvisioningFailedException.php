<?php

namespace App\Exceptions\Server\Application;

use App\Services\Server\ServerOpsResult;
use Exception;

/**
 * A provisioning step failed on the server. Carries the step that broke and
 * the server-ops log reference, so the user is told which part failed and can
 * quote the reference — without the raw stderr reaching the API.
 *
 * It also carries an optional `reason` code. The reference is enough for
 * someone with access to the server-ops log and useless to everyone else, and
 * there is one failure where the log has nothing to offer either: a process
 * killed by the kernel writes no stderr at all. `fromResult()` reads the exit
 * status and names that case, so "the build failed, here is a reference to an
 * empty log" becomes "the server ran out of memory during the build".
 */
class ProvisioningFailedException extends Exception
{
    /**
     * 128 + SIGKILL(9). The shell's encoding of "something killed this",
     * which on a server under memory pressure means the OOM killer chose it.
     * There is no other common way for these commands to be signalled.
     */
    private const EXIT_KILLED = 137;

    public function __construct(
        public readonly string $step,
        public readonly string $reference,
        public readonly ?string $reason = null,
    ) {
        parent::__construct("Provisioning failed at step [{$step}] (reference {$reference})");
    }

    /**
     * Build from a failed operation, classifying what the exit status says.
     *
     * Deliberately narrow: it names the one failure mode that is otherwise
     * undiagnosable and leaves everything else unclassified rather than
     * guessing. A wrong reason is worse than none — it sends the user to fix
     * something that was never broken.
     */
    public static function fromResult(string $step, ServerOpsResult $result): self
    {
        return new self($step, $result->reference, self::classify($result));
    }

    /**
     * `null` when the failure speaks for itself — the command's own output is
     * already in the log under this reference, and the user is better served
     * by it than by a category invented here.
     */
    private static function classify(ServerOpsResult $result): ?string
    {
        // A native addon needed compiling and this server has no compiler.
        //
        // Checked before the exit status because npm exits 1, which says
        // nothing on its own. node-gyp's wording is the unambiguous part:
        // "not found: make" is emitted only by its own `which` lookup for the
        // build tool, so there is no honest way to read it as anything else.
        //
        // Worth naming rather than leaving to the log, because the log is the
        // problem. npm writes thousands of peer-dependency warnings around
        // this one line, which is how a missing build-essential presented as
        // a dependency-resolution failure for two attempts on a real server.
        if (self::mentionsMissingBuildTool($result)) {
            return 'no_build_tools';
        }

        $exitCode = $result->result?->exitCode();

        if ($exitCode !== self::EXIT_KILLED) {
            return null;
        }

        // A killed process may still have written something before it died,
        // and if it did that is the better explanation. Only claim
        // out-of-memory for the silent case this exists to describe.
        if (trim($result->output()) !== '' || trim($result->errorOutput()) !== '') {
            return null;
        }

        return 'out_of_memory';
    }

    /**
     * Did node-gyp fail to find a build tool?
     *
     * Matched against both streams: npm puts its own summary on stderr and the
     * gyp transcript can land on either depending on how the install was run.
     *
     * `make` and `g++` by name rather than a looser "gyp ERR" match, because
     * gyp reports compile errors with the same prefix -- and a source file
     * that will not compile is a different problem with a different fix. This
     * is the class's own rule: a wrong reason sends someone to fix something
     * that was never broken.
     */
    private static function mentionsMissingBuildTool(ServerOpsResult $result): bool
    {
        $output = $result->errorOutput()."\n".$result->output();

        foreach (['not found: make', 'not found: g++', 'not found: cc', 'not found: gcc'] as $needle) {
            if (str_contains($output, $needle)) {
                return true;
            }
        }

        return false;
    }
}
