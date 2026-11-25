<?php

namespace App\Services\Server\Docker\Images;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Find an image on Docker Hub by what it is called, not by its exact name.
 *
 * Hub's own relevance order can put a third-party repackage above the project's image, so
 * results are re-ranked: exact name typed, official images, then pulls (the project's own
 * publisher's pulls weigh more).
 *
 * Hub's v2 search has no "Verified Publisher" flag, so `verified_publisher` is always false; the
 * field stays so the UI contract does not change later.
 *
 * Offline returns an empty list and `offline: true`, never an error: the picker still accepts a
 * typed reference.
 */
class ImageSearch
{
    /** How much more a pull of the project's own publisher counts, at most. */
    private const PUBLISHER_WEIGHT = 5;

    /**
     * The weight ramps from none at BOOST_FROM to full at BOOST_FULL. A ramp, not a step: a
     * hard floor let an unrelated 10,001-pull repackage beat the project's own 9,999.
     */
    private const BOOST_FROM = 1_000;

    private const BOOST_FULL = 50_000;

    /**
     * @return array{results: list<array<string, mixed>>, offline?: bool}
     */
    public function search(string $query, int $limit): array
    {
        $query = trim($query);
        $cacheKey = 'docker-image-search:'.sha1(strtolower($query));

        $results = Cache::get($cacheKey);

        if ($results === null) {
            $results = $this->fetch($query);

            if ($results === null) {
                return ['results' => [], 'offline' => true];
            }

            Cache::put($cacheKey, $results, now()->addMinutes((int) config('server.docker.images.search_cache_minutes', 10)));
        }

        return ['results' => array_slice($results, 0, $limit)];
    }

    /**
     * @return list<array<string, mixed>>|null null when Hub could not be asked
     */
    private function fetch(string $query): ?array
    {
        try {
            $response = Http::connectTimeout((int) config('server.docker.images.connect_timeout', 5))
                ->timeout((int) config('server.docker.images.timeout', 10))
                ->acceptJson()
                ->get('https://hub.docker.com/v2/search/repositories/', [
                    'query' => $query,
                    // More than asked for: re-ranking needs the right answer in the page.
                    'page_size' => 50,
                ]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $results = [];

        foreach ((array) $response->json('results', []) as $row) {
            if (! is_array($row) || ! is_string($row['repo_name'] ?? null) || $row['repo_name'] === '') {
                continue;
            }

            $results[] = [
                'image' => $row['repo_name'],
                'registry' => ImageReference::HUB,
                'description' => is_string($row['short_description'] ?? null) ? $row['short_description'] : '',
                'stars' => (int) ($row['star_count'] ?? 0),
                'pulls' => (int) ($row['pull_count'] ?? 0),
                'official' => (bool) ($row['is_official'] ?? false),
                'verified_publisher' => false,
            ];
        }

        $rank = fn (array $row): array => [
            strtolower($row['image']) === strtolower($query),
            $row['official'],
            $row['verified_publisher'],
            $this->popularity($row, $query),
        ];

        usort($results, fn (array $a, array $b): int => $rank($b) <=> $rank($a));

        return $results;
    }

    /**
     * Pulls, with a publisher that looks like the project weighted up.
     *
     * A weight, never a rank of its own: as a rank above pulls, anyone registering a matching
     * name with a handful of pulls took the preselected first place (which deploys in one click).
     * Weighted, a repository with next to no pulls gets next to no boost.
     *
     * The weight grows with pulls between BOOST_FROM and BOOST_FULL, so no single pull jumps a
     * repository past another.
     *
     * @param  array<string, mixed>  $row
     */
    private function popularity(array $row, string $query): int
    {
        $pulls = (int) $row['pulls'];

        if ($pulls <= self::BOOST_FROM || ! $this->publisherMatches((string) $row['image'], $query)) {
            return $pulls;
        }

        $share = min(1.0, ($pulls - self::BOOST_FROM) / (self::BOOST_FULL - self::BOOST_FROM));

        return (int) round($pulls * (1 + (self::PUBLISHER_WEIGHT - 1) * $share));
    }

    /**
     * Whether the publisher looks like the project: the name searched for is in the namespace and
     * IS the repository, as in `umamisoftware/umami`.
     *
     * The repository is matched whole: as a substring, vendor accessories such as
     * `grafana/grafana-image-renderer` were weighted as the project.
     */
    private function publisherMatches(string $image, string $query): bool
    {
        $plain = fn (string $value): string => (string) preg_replace('/[^a-z0-9]/', '', strtolower($value));
        $term = $plain((string) strrchr('/'.$query, '/'));

        if ($term === '' || ! str_contains($image, '/')) {
            return false;
        }

        [$namespace, $repository] = explode('/', $image, 2);

        return str_contains($plain($namespace), $term) && $plain($repository) === $term;
    }
}
