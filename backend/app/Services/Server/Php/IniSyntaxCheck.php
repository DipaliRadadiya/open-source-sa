<?php

namespace App\Services\Server\Php;

use App\Services\Server\ServerOpsResult;

/**
 * Reads a PHP config test for the failure its exit code does not report.
 *
 * PHP does not refuse to start over a php.ini it cannot parse. It prints
 *
 *     PHP:  syntax error, unexpected end of file, expecting ... on line 4
 *
 * and exits 0 — and then runs with every directive **after** the error
 * ignored. Measured on both stacks (2026-09-23): `lsphp -c php.ini -v` and
 * `php-fpm8.4 -t` each passed a file with an unclosed quote, and a
 * `max_input_vars = 3000` below it read back as the default 1000. So a config
 * test read by exit code alone let the ini editor save that file, reload, and
 * report success, while half of the user's settings silently stopped applying.
 *
 * Only the parser's own messages count. A `PHP Warning:` — a module loaded
 * twice, a missing .so — is a running PHP with a complaint, and treating it as
 * a failure would refuse every save on a server that already has one.
 */
final class IniSyntaxCheck
{
    private const PARSE_ERROR = '/^PHP:\s+(syntax error|Error parsing)/mi';

    /**
     * The same result, turned into a failure if PHP could not parse its ini.
     */
    public static function apply(ServerOpsResult $result): ServerOpsResult
    {
        if ($result->failed() || ! self::rejected($result)) {
            return $result;
        }

        return new ServerOpsResult(
            ok: false,
            reference: $result->reference,
            result: $result->result,
            answered: $result->answered,
        );
    }

    public static function rejected(ServerOpsResult $result): bool
    {
        return preg_match(self::PARSE_ERROR, $result->output()."\n".$result->errorOutput()) === 1;
    }
}
