<?php

namespace App\Services\Server\Applications\Installers\Concerns;

use App\Models\Application;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Version lookups shared by the installers that implement ApplicationUpdater.
 *
 * Every remote lookup answers null or an empty list on failure rather than
 * throwing: "we could not find out" is shown as such, and must never be read
 * as "there is nothing newer".
 */
trait ResolvesUpdates
{
    /** A file of the site's, read as root — the panel cannot traverse the user's home itself. */
    protected function readSiteFile(Application $application, string $path): ?string
    {
        $result = $this->serverOps->probe(['cat', '--', $path], [
            'feature' => 'application',
            'op' => 'update.read_version',
            'application' => $application->id,
        ]);

        return $result->ok ? $result->output() : null;
    }

    /** Composer's record of an installed package, without running Composer. */
    protected function composerInstalledVersion(Application $application, string $projectRoot, string $package): ?string
    {
        $json = json_decode((string) $this->readSiteFile($application, $projectRoot.'/vendor/composer/installed.json'), true);
        // Composer 2 wraps the list in `packages`; Composer 1 wrote the bare list.
        $packages = is_array($json) ? ($json['packages'] ?? $json) : [];

        foreach (is_array($packages) ? $packages : [] as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $package && is_string($entry['version'] ?? null)) {
                return $this->normalizeVersion($entry['version']);
            }
        }

        return null;
    }

    /**
     * Stable versions of a Composer package, from Packagist's metadata.
     *
     * @return array<int, string>
     */
    protected function packagistVersions(string $package): array
    {
        $json = $this->getJson('https://repo.packagist.org/p2/'.$package.'.json');

        return collect($json['packages'][$package] ?? [])
            ->pluck('version')
            ->filter(fn ($version): bool => is_string($version) && $this->isStable($version))
            ->map(fn (string $version): string => $this->normalizeVersion($version))
            ->values()
            ->all();
    }

    /**
     * Published, non-prerelease releases from a GitHub releases list.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function githubReleases(string $endpoint): array
    {
        $json = $this->getJson($endpoint, ['per_page' => 100]);

        return collect(is_array($json) ? $json : [])
            ->filter(fn ($release): bool => is_array($release)
                && ($release['draft'] ?? false) === false
                && ($release['prerelease'] ?? false) === false
                && is_string($release['tag_name'] ?? null)
                && $this->isStable($release['tag_name']))
            ->values()
            ->all();
    }

    /**
     * The highest of `$versions` sharing `$installed`'s major (or the first
     * `$parts` components of it — Moodle's branch is major.minor).
     *
     * @param  array<int, string>  $versions
     */
    protected function highestInLine(array $versions, string $installed, int $parts = 1): ?string
    {
        $line = $this->line($installed, $parts);

        return $this->highest(array_values(array_filter(
            $versions,
            fn (string $version): bool => $this->line($version, $parts) === $line,
        )));
    }

    /** @param  array<int, string>  $versions */
    protected function highest(array $versions): ?string
    {
        if ($versions === []) {
            return null;
        }

        usort($versions, fn (string $a, string $b): int => version_compare($b, $a));

        return $versions[0];
    }

    protected function line(string $version, int $parts = 1): string
    {
        return implode('.', array_slice(explode('.', $this->normalizeVersion($version)), 0, $parts));
    }

    protected function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }

    protected function isStable(string $version): bool
    {
        return preg_match('/^v?\d+\.\d+\.\d+$/', trim($version)) === 1;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null
     */
    protected function getJson(string $url, array $query = []): ?array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['Accept' => 'application/json', 'User-Agent' => 'control-panel'])
                ->get($url, $query);
        } catch (Throwable) {
            return null;
        }

        $json = $response->successful() ? $response->json() : null;

        return is_array($json) ? $json : null;
    }
}
