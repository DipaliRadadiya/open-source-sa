<?php

namespace App\Support;

use App\Services\Server\Metrics\ServerMetrics;

/**
 * What operating system this server is, read from `/etc/os-release`.
 *
 * Read with a plain file read, **not** through `ServerOps`. `/etc/os-release`
 * is world-readable by design — it is the file everything on the box uses to
 * identify the distribution — so routing it through `sudo -n` would invent a
 * permission failure that cannot otherwise happen, and then require handling
 * for it. That is exactly the mistake made with
 * `/run/systemd/shutdown/scheduled`, where careful refusal-vs-absence
 * reasoning guarded a failure mode created by choosing the wrong tool.
 * {@see ServerMetrics} already reads this file the
 * same way.
 *
 * Memoized per path for the request: several messages can name the OS, and the
 * answer cannot change while the process is running.
 */
final class OsRelease
{
    /** @var array<string, array<string, string>> */
    private static array $parsed = [];

    /**
     * A short human label for this release — "Ubuntu 26.04".
     *
     * Built from `NAME` + `VERSION_ID` rather than `PRETTY_NAME`, which carries
     * the point release and the edition ("Ubuntu 24.04.4 LTS"). A message
     * saying a vendor has not built for "Ubuntu 24.04.4 LTS" reads as though
     * the patch level were the problem.
     *
     * Null when the file cannot be read or says nothing useful — the caller
     * decides what to say instead, because "unknown" is not a sentence.
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
