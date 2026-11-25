<?php

namespace App\Services\Runtime;

use App\Models\AppPackageRelease;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which Node versions a one-click application's current release runs on.
 *
 * The range is read from the release's `engines.node` instead of being hand-copied into the
 * site type, so the installed version and the offered Node range cannot drift apart.
 *
 * Reads never touch the network (same rule as {@see NpmCatalog}): a panel behind a firewall
 * must not hang on a create form. A scheduled command fills the table; a site type with no
 * row falls back to the range it declares in code.
 */
class AppPackageCatalog
{
    /**
     * The Node range this package's current release declares, as the picker
     * wants it, or null when nothing is stored.
     *
     * @return array{min: ?string, max: ?string}|null
     */
    public function nodeRange(string $package): ?array
    {
        $row = AppPackageRelease::query()->find($package);

        if ($row === null) {
            return null;
        }

        return $this->parse((string) $row->node_range);
    }

    /**
     * Split an npm `engines.node` range into the floor and ceiling the site type API publishes.
     *
     * Deliberately narrow: a range it cannot read (unions, carets, pre-release tags) returns
     * null rather than a guess, so the caller falls back to the declared range.
     *
     * Understood:
     *
     *   >=24.0.0            → min 24.0.0, no ceiling
     *   >=20.19 <= 24.x     → min 20.19,  max 24
     *   >=22 <25            → min 22,     max 24        (exclusive, stepped down a major)
     *
     * @return array{min: ?string, max: ?string}|null
     */
    public function parse(string $range): ?array
    {
        $range = trim($range);

        if ($range === '') {
            return null;
        }

        // A union means disjoint windows; no single min/max describes them.
        if (str_contains($range, '||')) {
            return null;
        }

        if (preg_match('/>=\s*v?(\d+(?:\.\d+){0,2})/', $range, $low) !== 1) {
            return null;
        }

        $min = $low[1];
        $max = null;

        // `<= 24.x` and `<=24.9.9` both mean "the 24 line is fine".
        if (preg_match('/<=\s*v?(\d+)(?:\.(?:x|\d+))?/', $range, $high) === 1) {
            $max = $high[1];
        } elseif (preg_match('/<\s*v?(\d+)(?:\.(?:x|\d+))?/', $range, $high) === 1) {
            // `<25` excludes 25 entirely, so the highest allowed major is 24.
            $exclusive = (int) $high[1];

            if ($exclusive <= 0) {
                return null;
            }

            $max = (string) ($exclusive - 1);
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * Fetch each package's resolved release and store what it requires.
     * Called by a scheduled command, never by a request.
     *
     * @param  array<string, string>  $packages  package name => version pin
     * @return int the number of packages on record afterwards
     */
    public function refresh(array $packages): int
    {
        foreach ($packages as $package => $pin) {
            try {
                $release = $this->fetch($package, $pin);
            } catch (Throwable $e) {
                // Keep what is stored: blanking a good range would widen the picker to versions
                // the application refuses.
                Log::warning('app package refresh failed', [
                    'package' => $package,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($release === null) {
                // Log it: a refresh that fetched nothing and a never-refreshed package leave the
                // same empty table.
                Log::warning('app package release not readable', [
                    'package' => $package,
                    'pin' => $pin,
                ]);

                continue;
            }

            AppPackageRelease::updateOrCreate(
                ['package' => $package],
                ['version' => $release['version'], 'node_range' => $release['node_range']],
            );
        }

        return AppPackageRelease::query()->count();
    }

    /**
     * One release's version and Node requirement, from the registry.
     *
     * The registry resolves a dist-tag (`/n8n/latest`). A pin naming a bare major (`1`) is not a
     * dist-tag and has no such endpoint; it returns null and keeps the declared range.
     *
     * @return array{version: string, node_range: string}|null
     */
    private function fetch(string $package, string $pin): ?array
    {
        // No `application/vnd.npm.install-v1+json` header (unlike NpmCatalog): it is only defined
        // for the package endpoint, and this single-version endpoint may answer 406 depending on
        // the CDN edge, which would silently store nothing.
        $response = Http::timeout((int) config('server.runtimes.npm.timeout', 30))
            ->get('https://registry.npmjs.org/'.rawurlencode($package).'/'.rawurlencode($pin));

        if (! $response->successful()) {
            return null;
        }

        $version = $response->json('version');
        $engines = $response->json('engines.node');

        if (! is_string($version) || ! is_string($engines) || $engines === '') {
            return null;
        }

        return ['version' => $version, 'node_range' => $engines];
    }
}
