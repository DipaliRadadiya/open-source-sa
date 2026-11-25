<?php

namespace App\Services\Runtime;

use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * Turns a failed install into a stable reason code.
 *
 * A code rather than the output: the output is untranslatable, names paths and versions that
 * mean nothing to the reader, and changes wording between apt releases.
 *
 * Anything unrecognised is `unknown`, which still carries the reference. Guessing a specific
 * cause from output we do not recognise would be worse than admitting we do not know.
 *
 * Takes the whole result, not a string, so two mistakes cannot be made:
 *
 *  - `output()` is **stdout only**, but installers put failures on stderr (apt's `E:` lines,
 *    fnm's errors, `sudo: a password is required`); matching only stdout yields `unknown`.
 *  - `$result->denied` must not be ignored: {@see ServerOps} already recognises a refused sudo
 *    and has a message naming the command that repairs it.
 */
class InstallFailureClassifier
{
    public function classify(string $runtime, ServerOpsResult $result): string
    {
        /*
         * Checked before any pattern, because it is not a pattern: ServerOps established the
         * refusal from the `sudo` call itself, and the remedy is specific ("run
         * `artisan panel:sudoers`"). Leaving it to the regex list would need a pattern per
         * runtime, and the one that forgot would say `unknown`.
         */
        if ($result->denied) {
            return 'sudo_denied';
        }

        // Both streams: the one carrying the failure depends on the tool (apt reports "No space
        // left on device" on stdout and "Unable to locate package" on stderr).
        $output = $result->errorOutput()."\n".$result->output();

        $patterns = (array) config("server.runtimes.{$runtime}.failure_reasons", []);

        foreach ($patterns as $reason => $pattern) {
            if (preg_match($pattern, $output) === 1) {
                return (string) $reason;
            }
        }

        return 'unknown';
    }
}
