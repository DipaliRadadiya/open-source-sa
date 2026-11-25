<?php

namespace App\Services\Runtime;

use App\Models\NpmRelease;
use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which npm a given Node version can actually run.
 *
 * "The latest npm" is not one number: npm 12 declares
 * `engines.node: ^22.22.2 || ^24.15.0 || >=26.0.0`, so on Node 20 the newest usable npm is 11.x,
 * and `npm install -g npm@latest` there installs an npm that cannot start. So the answer is
 * computed per Node version: the highest npm release whose `engines.node` that Node satisfies.
 *
 * Reads never touch the network. A scheduled command fills `npm_releases`; a panel behind a
 * firewall gets null and the frontend shows no comparison.
 *
 * One release per npm major is stored (the newest of each line). That can under-offer by a
 * patch but never offers something that will not run.
 */
class NpmCatalog
{
    /** @var array<int, array{version: string, node_range: string}>|null */
    private ?array $releases = null;

    /**
     * The newest npm this Node version can run, or null when unknown.
     *
     * Null means "we cannot say": never refreshed, no egress, or nothing matches.
     */
    public function latestFor(string $nodeVersion): ?string
    {
        $best = null;

        foreach ($this->all() as $release) {
            if (! $this->satisfies($nodeVersion, $release['node_range'])) {
                continue;
            }

            if ($best === null || Comparator::greaterThan($release['version'], $best)) {
                $best = $release['version'];
            }
        }

        return $best;
    }

    /**
     * Is there an npm worth installing on this Node version?
     *
     * Semver comparison, not string: '9.8.1' vs '10.2.4' must not say the older one is newer.
     */
    public function updateAvailable(string $nodeVersion, ?string $installed): bool
    {
        $latest = $this->latestFor($nodeVersion);

        if ($latest === null || $installed === null || $installed === '') {
            return false;
        }

        return Comparator::greaterThan($latest, $installed);
    }

    /**
     * The npm to install on this Node version, refreshing the catalog first if the stored data
     * cannot answer.
     *
     * The one reader that touches the network, because it is about to install something and
     * the alternative to fetching is guessing. Null means "we do not know what to install";
     * the caller must refuse rather than fall back to `npm@latest`.
     */
    public function resolveTarget(string $nodeVersion): ?string
    {
        $target = $this->latestFor($nodeVersion);

        if ($target !== null) {
            return $target;
        }

        $this->refresh();

        return $this->latestFor($nodeVersion);
    }

    /**
     * Fetch the registry and replace what is stored. Scheduled command only, never a request.
     *
     * @return int the number of npm majors on record afterwards
     */
    public function refresh(): int
    {
        try {
            $fresh = $this->fetch();
        } catch (Throwable $e) {
            // Keep what is stored; a network blip must not blank it.
            Log::warning('npm catalog refresh failed', ['error' => $e->getMessage()]);

            return NpmRelease::query()->count();
        }

        foreach ($fresh as $major => $row) {
            NpmRelease::updateOrCreate(
                ['major' => (string) $major],
                ['version' => $row['version'], 'node_range' => $row['node_range']],
            );
        }

        $this->releases = null;

        return $fresh === [] ? NpmRelease::query()->count() : count($fresh);
    }

    /**
     * @return array<int, array{version: string, node_range: string}>
     */
    private function all(): array
    {
        return $this->releases ??= NpmRelease::query()
            ->get()
            ->map(fn (NpmRelease $row) => ['version' => $row->version, 'node_range' => $row->node_range])
            ->all();
    }

    /**
     * npm's ranges are the same dialect Composer parses. An unparsable range counts as "does not
     * match" instead of throwing, so one odd release cannot take down the Node screen.
     */
    private function satisfies(string $nodeVersion, string $range): bool
    {
        try {
            return Semver::satisfies($nodeVersion, $range);
        } catch (Throwable $e) {
            Log::warning('npm engines range not understood', ['range' => $range, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * The newest release of each npm major, from the registry's abbreviated packument.
     *
     * @return array<string, array{version: string, node_range: string}>
     */
    private function fetch(): array
    {
        $response = Http::timeout((int) config('server.runtimes.npm.timeout', 30))
            // Without this header the registry returns the full metadata, README included.
            ->withHeaders(['Accept' => 'application/vnd.npm.install-v1+json'])
            ->get((string) config('server.runtimes.npm.registry_url'));

        if (! $response->successful()) {
            return [];
        }

        $out = [];

        foreach ($response->json('versions') ?? [] as $version => $meta) {
            $version = (string) $version;

            // Pre-releases are npm's testing lines; never offer one.
            if (str_contains($version, '-')) {
                continue;
            }

            $major = explode('.', $version)[0];

            if (isset($out[$major]) && ! Comparator::greaterThan($version, $out[$major]['version'])) {
                continue;
            }

            $out[$major] = [
                'version' => $version,
                // Absent `engines` means "runs anywhere"; only very old releases, which the
                // newest compatible release always outranks.
                'node_range' => (string) ($meta['engines']['node'] ?? '*'),
            ];
        }

        return $out;
    }
}
