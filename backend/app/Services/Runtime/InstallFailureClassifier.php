<?php

namespace App\Services\Runtime;

/**
 * Turns the output of a failed install into a stable reason code.
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
 */
class InstallFailureClassifier
{
    public function classify(string $runtime, string $output): string
    {
        $patterns = (array) config("server.runtimes.{$runtime}.failure_reasons", []);

        foreach ($patterns as $reason => $pattern) {
            if (preg_match($pattern, $output) === 1) {
                return (string) $reason;
            }
        }

        return 'unknown';
    }
}
