<?php

namespace App\Services\Runtime;

use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;

/**
 * Turns a failed install into a stable reason code.
 *
 * Why a code and not the output: the output is untranslatable, it names paths
 * and package versions that mean nothing to the person reading, and its
 * wording changes between apt releases. A code can be rendered in eight
 * locales, switched on by the frontend, and kept stable while the sentence
 * behind it is rewritten.
 *
 * Anything unrecognised is `unknown` — which still carries the reference, so
 * the raw detail is one server-ops log lookup away. Guessing a specific cause
 * from output we do not recognise would be worse than admitting we do not
 * know.
 *
 * **This takes the whole result, not a string, and that is the point.** It used
 * to take `$result->output()` from three separate call sites, which made two
 * mistakes possible and both were being made:
 *
 *  - `output()` is **stdout only**. Installers put their failures on stderr —
 *    apt's `E:` lines, fnm's errors, and `sudo: a password is required` all go
 *    there — so the patterns were matched against the stream that did not
 *    contain the failure, and nearly everything came back `unknown`.
 *  - `$result->denied` was ignored. {@see ServerOps}
 *    already recognises a refused sudo and there is a message that names the
 *    command which repairs it; classifying the text instead threw that away and
 *    told the user to contact support.
 *
 * Measured on 2026-09-14: a Node install on a server whose grant did not cover
 * `fnm` reported `reason: unknown`, message "The install failed. Quote the
 * reference below to support." — while displaying `sudo: a password is
 * required` directly above it. The panel showed the cause and said it did not
 * know the cause, in the same box.
 *
 * Taking the result makes stdout-only classification unexpressible rather than
 * merely discouraged.
 */
class InstallFailureClassifier
{
    public function classify(string $runtime, ServerOpsResult $result): string
    {
        /*
         * Checked before any pattern, because it is not a pattern.
         *
         * A refused sudo is a fact ServerOps established from the exit status
         * and stderr of the `sudo` call itself, not a guess about installer
         * output — and the remedy is specific and actionable ("your grant is
         * older than the panel; run `artisan panel:sudoers`"). Leaving it to
         * the regex list would mean every runtime needing its own pattern for
         * the same failure, and the one that forgot would say `unknown`.
         */
        if ($result->denied) {
            return 'sudo_denied';
        }

        // Both streams. Which one carries the failure depends on the tool, and
        // on a bad day on the phase of the moon: apt reports "No space left on
        // device" on stdout and "Unable to locate package" on stderr.
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
