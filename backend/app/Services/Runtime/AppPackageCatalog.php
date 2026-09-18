<?php

namespace App\Services\Runtime;

use App\Models\AppPackageRelease;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which Node versions a one-click application's current release runs on.
 *
 * The problem this removes: the version a site type installs and the Node
 * range it allows had to agree, and were maintained by hand in two different
 * files. n8n's installer resolves `latest`, while `N8nSiteType` declared
 * `20.19`–`24` — numbers transcribed from the registry months earlier. By the
 * time anybody looked, n8n 1.x's `>=20.19 <= 24.x` had become 2.x's
 * `>=24.0.0`, with no ceiling at all. Nothing reported the drift, because the
 * failure it causes happens later and elsewhere: a site installs without
 * complaint and then refuses to start on the only Node the form offered.
 *
 * So the range is read off the package instead of remembered. Whatever
 * `engines.node` the resolved release declares is what the picker offers, and
 * the two facts cannot disagree because there is now only one of them.
 *
 * Reads never touch the network, the same rule {@see NpmCatalog} follows: a
 * self-hosted panel behind a firewall must not hang on a create form because
 * registry.npmjs.org is unreachable. A scheduled command fills the table, and
 * a site type with no row falls back to the range it declares in code — the
 * last answer anybody had, which is better than no form at all.
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
     * Split an npm `engines.node` range into the floor and ceiling the site
     * type API publishes.
     *
     * Deliberately narrow. npm's dialect can express things this does not
     * attempt — unions, carets, pre-release tags — and a range it cannot read
     * confidently returns null rather than a guess, which falls the caller
     * back to the declared range. A wrong bound here does not show up as an
     * error; it shows up as a version quietly missing from a dropdown, so
     * "not sure" has to stay expressible.
     *
     * Understood, because they are what these packages actually publish:
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

        // A union means several disjoint windows and there is no single
        // min/max that describes them honestly.
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
                // Keep what is stored. A network blip must not blank a range
                // that was correct yesterday — and blanking it would widen the
                // picker back to versions the application refuses.
                Log::warning('app package refresh failed', [
                    'package' => $package,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($release === null) {
                // Said out loud. A refresh that fetches nothing and a package
                // that has never been refreshed produce the same empty table,
                // and without this line the difference is unknowable — which
                // is exactly the position I was in when this returned zero
                // once and could not be made to do it again.
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
     * The registry resolves a dist-tag for us — `/n8n/latest` is the release
     * npm itself would install — so the pin does not have to be understood
     * here. A pin that names a bare major (`1`) is not a dist-tag and has no
     * such endpoint; those return null and keep the declared range.
     *
     * @return array{version: string, node_range: string}|null
     */
    private function fetch(string $package, string $pin): ?array
    {
        $response = Http::timeout((int) config('server.runtimes.npm.timeout', 30))
            // Same header NpmCatalog sends: without it the registry returns
            // every release's full metadata for a document we read two fields
            // from.
            ->withHeaders(['Accept' => 'application/vnd.npm.install-v1+json'])
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
