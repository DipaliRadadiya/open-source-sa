<?php

namespace App\Services\Server\Php;

use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Cache;

/**
 * The exact version of an installed PHP — `8.4.14`, where the rest of the
 * panel only ever knows `8.4`.
 *
 * Asked of the interpreter itself rather than apt: the binary is what runs
 * the site, and its own answer cannot disagree with it the way a package
 * database can (a hand-built or hand-replaced binary, an apt hold).
 *
 * `-n` so no php.ini is loaded. That is what makes the answer clean — a broken
 * ini or an extension that warns at startup prints on stdout ahead of the
 * version — and cheap, since no extension is loaded just to print a constant.
 * `PHP_VERSION` is core and needs neither.
 *
 * Never throws and never fails the screen: a binary that is missing, hangs,
 * exits non-zero or prints something that is not a version is `null`, which
 * the PHP screen reads as "show the minor version we already had".
 */
class PhpFullVersion
{
    /**
     * Seconds a single binary may take. Starting `php -n` is milliseconds; this
     * is only the ceiling for a binary that has hung, which must not hold the
     * whole PHP screen with it.
     */
    private const TIMEOUT_SECONDS = 3;

    /** A read answer stays good until the binary changes; this is only a safety net. */
    private const FOUND_TTL_SECONDS = 86400;

    /**
     * A failed read is remembered briefly: the screen is polled while an
     * install runs, and a hung binary would otherwise cost the timeout on every
     * poll and write an error to the ops log each time.
     */
    private const MISSED_TTL_SECONDS = 60;

    public function __construct(private ServerOps $serverOps) {}

    /**
     * @param  string  $binary  the interpreter's path, as listed on the PHP screen
     * @param  string  $version  the minor version that row claims, e.g. `8.4`
     */
    public function read(string $binary, string $version): ?string
    {
        // A bare name ("php", from a blank `php_binary_pattern`) is resolved
        // through PATH: there is no file to look at, so nothing to key a cache
        // on and nothing to pre-check.
        if (! str_contains($binary, '/')) {
            return $this->ask($binary, $version);
        }

        // A path that is not an executable is a version whose CLI is not
        // installed — an FPM package can be present without php-cli — and is
        // not worth a spawn or an error in the ops log.
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

        // A binary answering for a different minor than its row is not this
        // row's version: a pattern that resolves every version to one `php` on
        // PATH, or a stale symlink. Showing "8.3.9" under 8.4 would be worse
        // than showing nothing.
        if ($exact === null || ! str_starts_with($exact, $version.'.')) {
            return null;
        }

        return $exact;
    }

    /**
     * `8.4.14` from `8.4.14`, `8.1.2-1ubuntu2.14` and `8.5.0RC1`.
     *
     * A distribution's packaging suffix (`-1ubuntu…`, `+deb…`) says nothing
     * about PHP and is dropped. A real pre-release marker is the version's
     * identity — `8.5.0RC1` is not `8.5.0` — and is kept. Anything else is not
     * a version.
     */
    private function parse(string $output): ?string
    {
        if (preg_match('/^(\d+\.\d+\.\d+(?:(?:alpha|beta|RC)\d+)?)(?:[-+~][\w.+~-]*)?$/', $output, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The binary's path, size and modification time, so an upgrade — which
     * replaces the file — is a different key and the old answer is never
     * served for the new binary.
     */
    private function cacheKey(string $binary, string $version): string
    {
        clearstatcache(true, $binary);

        return 'php-full-version:'.md5($binary.'|'.$version).':'
            .(@filemtime($binary) ?: 0).'-'.(@filesize($binary) ?: 0);
    }
}
