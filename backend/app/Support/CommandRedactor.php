<?php

namespace App\Support;

/**
 * Removes credential values from command lines while preserving their shape.
 */
class CommandRedactor
{
    private const SECRET_WORDS = '(?:pass(?:wd|word|phrase)?|secret|token|api[-_]?key|private[-_]?key)';

    /**
     * @param  array<int, string>  $arguments
     */
    public static function arguments(array $arguments): string
    {
        $safe = [];
        $redactNext = false;

        foreach ($arguments as $argument) {
            if ($redactNext) {
                $safe[] = '[REDACTED]';
                $redactNext = false;

                continue;
            }

            if (preg_match('/^(--[a-z0-9_-]*'.self::SECRET_WORDS.'[a-z0-9_-]*)(?:=(.*))?$/i', $argument, $matches)) {
                $safe[] = isset($matches[2]) ? $matches[1].'=[REDACTED]' : $matches[1];
                $redactNext = ! isset($matches[2]);

                continue;
            }

            $safe[] = $argument;
        }

        return self::line(implode(' ', $safe));
    }

    public static function line(string $command): string
    {
        $value = preg_replace(
            '/\b([a-z][a-z0-9+.-]*:\/\/[^:\s\/@]+:)[^@\s\/]+(@)/i',
            '$1[REDACTED]$2',
            $command,
        ) ?? $command;

        $patterns = [
            '/([?&](?:token|key|secret|password|passwd|api[-_]?key|signature)=)[^&\s]+/i' => '$1[REDACTED]',
            '/\b(authorization\s*[:=]\s*bearer\s+)\S+/i' => '$1[REDACTED]',
            '/\b(bearer\s+)\S+/i' => '$1[REDACTED]',
            '/(?<![?&])((?:--?[a-z0-9_-]*'.self::SECRET_WORDS.'[a-z0-9_-]*|[a-z0-9_]*'.self::SECRET_WORDS.'[a-z0-9_]*)=)(?:"[^"]*"|\'[^\']*\'|\S+)/i' => '$1[REDACTED]',
            '/((?:--?[a-z0-9_-]*'.self::SECRET_WORDS.'[a-z0-9_-]*)\s+)(?:"[^"]*"|\'[^\']*\'|\S+)/i' => '$1[REDACTED]',
        ];

        $value = preg_replace(array_keys($patterns), array_values($patterns), $value) ?? $value;

        return self::statements($value);
    }

    /**
     * Passwords inside database statements, which an engine repeats in its
     * own error message (bug #22): a failed `CREATE USER` put `IDENTIFIED BY
     * '<password>'` into the server log and onto Admin → Error logs.
     *
     * The quote counts are loose on purpose: MySQL quotes the statement it
     * complains about (`near 'IDENTIFIED BY 'x''`), PostgreSQL doubles quotes
     * inside a literal, and MongoDB is JavaScript.
     */
    public static function statements(string $value): string
    {
        $patterns = [
            // MySQL/MariaDB: IDENTIFIED BY 'x', IDENTIFIED WITH plugin BY 'x',
            // IDENTIFIED BY PASSWORD 'hash', IDENTIFIED VIA plugin USING 'x'.
            "/(\bIDENTIFIED\s+(?:(?:WITH|VIA)\s+\S+\s+)?(?:BY|AS|USING)\s+(?:PASSWORD\s+)?)'(?:[^'\\\\]|\\\\.|'')*'/i" => "$1'[REDACTED]'",
            // SET PASSWORD = 'x', PASSWORD('x'), and PostgreSQL's
            // [ENCRYPTED] PASSWORD 'x' / E'x'.
            "/(\bPASSWORD\s*(?:=\s*|\(\s*)?E?)'(?:[^'\\\\]|\\\\.|'')*'/i" => "$1'[REDACTED]'",
            // SET PASSWORD [FOR account] = 'x' | PASSWORD('x').
            "/(\bSET\s+PASSWORD\b[^=;]*=\s*(?:PASSWORD\s*\(\s*)?)'(?:[^'\\\\]|\\\\.|'')*'/i" => "$1'[REDACTED]'",
            // MySQL/MariaDB quote the statement from the token they choked on:
            // `near 'BY 'secret'' at line 1`, or `near 'secret'' …` when that
            // token is the password itself, so no keyword is left to anchor
            // on. The whole snippet goes (found live on MariaDB 11.8); the
            // error code and message before it stay.
            "/(\bnear\s+)'.*?'(?=\s+at\s+line\s+\d+)/s" => "$1'[REDACTED]'",
            // MongoDB: pwd: "x", 'pwd': 'x', "pwd":"x".
            '/((?:\bpwd|["\']pwd["\'])\s*:\s*)(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')/i' => '$1"[REDACTED]"',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $value) ?? $value;
    }
}
