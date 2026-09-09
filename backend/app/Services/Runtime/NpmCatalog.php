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
 * "The latest npm" sounds like one number and is not. npm 12 declares
 * `engines.node: ^22.22.2 || ^24.15.0 || >=26.0.0`, so on a box running Node
 * 20 the newest usable npm is 11.x. Showing the registry's `latest` on every
 * row would leave the update button lit forever on the versions that can
 * never reach it — and, worse, `npm install -g npm@latest` on such a box
 * installs an npm that cannot start.
 *
 * So the answer is computed per Node version: the highest npm release whose
 * `engines.node` that Node satisfies.
 *
 * Reads never touch the network. The registry is fetched by a scheduled
 * command into `npm_releases`; a panel behind a firewall gets null and the
 * frontend shows no comparison, which is honest, rather than a page that
 * hangs for ten seconds on a registry it cannot reach.
 *
 * One release per npm major is stored — the newest of each line. That is
 * deliberately conservative: a Node 22.14 box is offered the newest 11.x
 * rather than some earlier 12.0.x whose range it might have satisfied. It can
 * under-offer by a patch; it cannot offer something that will not run.
 */
class NpmCatalog
{
    /** @var array<int, array{version: string, node_range: string}>|null */
    private ?array $releases = null;

    /**
     * The newest npm this Node version can run, or null when unknown.
     *
     * Null means one of: the catalog has never been refreshed, the box has no
     * egress, or nothing in the catalog matches. All three are "we cannot say"
     * and none of them should produce a number.
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
     * Computed here rather than left to the caller because the comparison is
     * a semver one: string-comparing '9.8.1' against '10.2.4' says the older
     * release is newer, which is exactly the bug this answers for the client.
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
     * Fetch the registry and replace what is stored. Called by a scheduled
     * command, never by a request.
     *
     * @return int the number of npm majors on record afterwards
     */
    public function refresh(): int
    {
        try {
            $fresh = $this->fetch();
        } catch (Throwable $e) {
            // Keep what is stored. A network blip must not blank a comparison
            // that was correct yesterday.
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
     * npm's ranges (`^22.22.2 || ^24.15.0 || >=26.0.0`) are the same dialect
     * Composer parses. A range it cannot parse is treated as "does not match"
     * rather than allowed to throw: one odd release must not take down the
     * whole Node screen.
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
     * The newest release of each npm major, from the registry's abbreviated
     * packument — the same document `npm` itself installs from, and a fifth
     * of the size of the full one.
     *
     * @return array<string, array{version: string, node_range: string}>
     */
    private function fetch(): array
    {
        $response = Http::timeout((int) config('server.runtimes.npm.timeout', 30))
            // Without this header the registry returns every release's full
            // metadata — README included — for a document we read two fields
            // from.
            ->withHeaders(['Accept' => 'application/vnd.npm.install-v1+json'])
            ->get((string) config('server.runtimes.npm.registry_url'));

        if (! $response->successful()) {
            return [];
        }

        $out = [];

        foreach ($response->json('versions') ?? [] as $version => $meta) {
            $version = (string) $version;

            // Pre-releases are npm's own testing lines. A panel should not
            // offer one to somebody's production box.
            if (str_contains($version, '-')) {
                continue;
            }

            $major = explode('.', $version)[0];

            if (isset($out[$major]) && ! Comparator::greaterThan($version, $out[$major]['version'])) {
                continue;
            }

            $out[$major] = [
                'version' => $version,
                // Absent `engines` is npm's own "runs anywhere" — only true of
                // releases old enough that no modern Node would ever pick
                // them, since the newest compatible release always wins.
                'node_range' => (string) ($meta['engines']['node'] ?? '*'),
            ];
        }

        return $out;
    }
}
