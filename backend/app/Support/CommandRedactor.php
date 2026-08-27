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

        return preg_replace(array_keys($patterns), array_values($patterns), $value) ?? $value;
    }
}
