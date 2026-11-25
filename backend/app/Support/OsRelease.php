<?php

namespace App\Support;

use App\Services\Server\Metrics\ServerMetrics;

/**
 * What operating system this server is, read from `/etc/os-release`.
 *
 * Read with a plain file read, **not** through `ServerOps`: the file is world-readable, so `sudo -n`
 * would only invent a permission failure. {@see ServerMetrics} reads it the same way.
 *
 * Memoized per path: the answer cannot change while the process runs.
 */
final class OsRelease
{
    /** @var array<string, array<string, string>> */
    private static array $parsed = [];

    /**
     * A short human label for this release — "Ubuntu 26.04".
     *
     * Built from `NAME` + `VERSION_ID`, not `PRETTY_NAME`: its point release and edition
     * ("Ubuntu 24.04.4 LTS") make a message read as if the patch level were the problem.
     *
     * Null when the file cannot be read or says nothing useful; the caller words the fallback.
     */
    public static function label(): ?string
    {
        $values = self::values();

        $name = $values['NAME'] ?? null;
        $version = $values['VERSION_ID'] ?? null;

        if ($name !== null && $version !== null) {
            return trim($name.' '.$version);
        }

        return $values['PRETTY_NAME'] ?? $name ?? null;
    }

    public static function codename(): ?string
    {
        return self::values()['VERSION_CODENAME'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function values(): array
    {
        $path = (string) config('server.os_release', '/etc/os-release');

        if (array_key_exists($path, self::$parsed)) {
            return self::$parsed[$path];
        }

        $values = [];
        $contents = is_file($path) ? (string) @file_get_contents($path) : '';

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $m) === 1) {
                $values[$m[1]] = trim($m[2], "\"'");
            }
        }

        return self::$parsed[$path] = $values;
    }

    /** Tests point `server.os_release` at a fixture; the memo has to follow. */
    public static function flush(): void
    {
        self::$parsed = [];
    }
}
