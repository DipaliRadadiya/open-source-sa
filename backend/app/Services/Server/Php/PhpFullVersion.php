<?php

namespace App\Services\Server\Php;

use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Cache;

/**
 * The exact version of an installed PHP — `8.4.14`, where the rest of the
 * panel only ever knows `8.4`.
 *
 * Asked of the interpreter itself, not apt: the binary is what runs the site, and a package
 * database can disagree with it (hand-built binary, apt hold).
 *
 * `-n` loads no php.ini, so a broken ini or a warning extension cannot print ahead of the
 * version; `PHP_VERSION` is core and needs neither.
 *
 * Never throws: a binary that is missing, hangs, exits non-zero or prints a non-version gives
 * `null`, and the PHP screen falls back to the minor version.
 */
class PhpFullVersion
{
    /**
     * Seconds a single binary may take; only a ceiling so a hung binary cannot hold the PHP screen.
     */
    private const TIMEOUT_SECONDS = 3;

    /** A read answer stays good until the binary changes; this is only a safety net. */
    private const FOUND_TTL_SECONDS = 86400;

    /**
     * A failed read is remembered briefly: the screen is polled during installs, and a hung binary
     * would otherwise cost the timeout and an ops-log error on every poll.
     */
    private const MISSED_TTL_SECONDS = 60;

    public function __construct(private ServerOps $serverOps) {}

    /**
     * @param  string  $binary  the interpreter's path, as listed on the PHP screen
     * @param  string  $version  the minor version that row claims, e.g. `8.4`
     */
    public function read(string $binary, string $version): ?string
    {
        // A bare name ("php") is resolved through PATH: no file to cache on or pre-check.
        if (! str_contains($binary, '/')) {
            return $this->ask($binary, $version);
        }

        // Not executable means the CLI is not installed (FPM can exist without php-cli); skip the spawn.
        if (! is_executable($binary)) {
            return null;
        }

        $key = $this->cacheKey($binary, $version);

        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        $found = $this->ask($binary, $version);

        Cache::put($key, $found ?? '', $found === null ? self::MISSED_TTL_SECONDS : self::FOUND_TTL_SECONDS);

        return $found;
    }

    private function ask(string $binary, string $version): ?string
    {
        $result = $this->serverOps->run(
            [$binary, '-n', '-r', 'echo PHP_VERSION;'],
            ['feature' => 'php', 'op' => 'full_version', 'version' => $version],
            timeout: self::TIMEOUT_SECONDS,
            retryAttempts: 1,
        );

        if ($result->failed()) {
            return null;
        }

        $exact = $this->parse(trim($result->output()));

        // A binary answering for a different minor than its row (one `php` on PATH, a stale
        // symlink) is wrong; showing "8.3.9" under 8.4 is worse than nothing.
        if ($exact === null || ! str_starts_with($exact, $version.'.')) {
            return null;
        }

        return $exact;
    }

    /**
     * `8.4.14` from `8.4.14`, `8.1.2-1ubuntu2.14` and `8.5.0RC1`.
     *
     * A distribution's packaging suffix (`-1ubuntu…`, `+deb…`) is dropped; a pre-release marker
     * is part of the version (`8.5.0RC1` is not `8.5.0`) and is kept.
     */
    private function parse(string $output): ?string
    {
        if (preg_match('/^(\d+\.\d+\.\d+(?:(?:alpha|beta|RC)\d+)?)(?:[-+~][\w.+~-]*)?$/', $output, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The binary's path, size and modification time, so an upgrade gets a new key.
     */
    private function cacheKey(string $binary, string $version): string
    {
        clearstatcache(true, $binary);

        return 'php-full-version:'.md5($binary.'|'.$version).':'
            .(@filemtime($binary) ?: 0).'-'.(@filesize($binary) ?: 0);
    }
}
