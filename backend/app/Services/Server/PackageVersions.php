<?php

namespace App\Services\Server;

use Illuminate\Support\Facades\Cache;

/**
 * The installed version of a service's package, read from dpkg.
 *
 * One `dpkg-query` for every package the Services screen asks about, cached
 * until dpkg's own database changes: the screen polls every three seconds,
 * and a version only changes when a package is installed, upgraded or
 * removed — every one of which rewrites `/var/lib/dpkg/status`, so its
 * modification time is the cache key and an upgrade shows on the next poll.
 *
 * Read-only. Nothing a client sends reaches the command: the package names
 * come from the services catalog in config.
 */
class PackageVersions
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * The version of the first candidate that is installed, or null when none
     * is (or dpkg could not be asked). A candidate may be a dpkg pattern
     * (`postgresql-[0-9]*`); when it matches several packages the highest
     * version wins.
     *
     * @param  array<int, string>  $candidates
     * @param  array<string, string>  $installed  from {@see installed()}
     * @return array{version: string, package_version: string}|null
     */
    public function pick(array $candidates, array $installed): ?array
    {
        foreach ($candidates as $candidate) {
            $matches = array_filter(
                $installed,
                fn (string $package) => fnmatch($candidate, $package),
                ARRAY_FILTER_USE_KEY,
            );

            if ($matches === []) {
                continue;
            }

            uasort($matches, fn (string $a, string $b) => version_compare(self::short($b), self::short($a)));
            $full = (string) reset($matches);

            return ['version' => self::short($full), 'package_version' => $full];
        }

        return null;
    }

    /**
     * Every installed package among the given names or patterns, as
     * package => full version.
     *
     * @param  array<int, string>  $patterns
     * @return array<string, string>
     */
    public function installed(array $patterns): array
    {
        $patterns = array_values(array_unique(array_filter($patterns)));

        if ($patterns === []) {
            return [];
        }

        $status = (string) config('server.dpkg_status', '/var/lib/dpkg/status');
        $key = 'package-versions:'.md5(implode("\n", $patterns)).':'.(@filemtime($status) ?: 0);

        return Cache::remember($key, now()->addDay(), fn () => $this->query($patterns));
    }

    /**
     * The upstream part of a Debian version, for display: `1:10.11.14-0ubuntu0.24.04.1`
     * → `10.11.14`, `5:27.3.1-1~ubuntu.24.04~noble` → `27.3.1`. Falls back to the
     * full string when it does not start with a number.
     */
    public static function short(string $version): string
    {
        $withoutEpoch = preg_replace('/^\d+:/', '', $version) ?? $version;

        return preg_match('/^\d+(?:\.\d+)*/', $withoutEpoch, $m) === 1 ? $m[0] : $version;
    }

    /**
     * @param  array<int, string>  $patterns
     * @return array<string, string>
     */
    private function query(array $patterns): array
    {
        $result = $this->serverOps->run(
            ['dpkg-query', '-W', '-f=${db:Status-Abbrev}\t${Package}\t${Version}\n', ...$patterns],
            ['feature' => 'service', 'op' => 'package_versions'],
            timeout: 15,
            // 1 = some name matched no package, which is the normal answer for
            // a service this stack does not have; the rest are still printed.
            expectedExitCodes: [1],
        );

        $installed = [];

        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            [$status, $package, $version] = array_pad(explode("\t", $line, 3), 3, '');

            // `ii` = wanted and installed. A removed package that left its
            // config behind (`rc`) has a version but no software.
            if (str_starts_with($status, 'ii') && $package !== '' && $version !== '') {
                $installed[$package] = trim($version);
            }
        }

        return $installed;
    }
}
