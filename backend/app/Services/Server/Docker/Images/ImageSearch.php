<?php

namespace App\Services\Server\Docker\Images;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Find an image on Docker Hub by what it is called, not by its exact name.
 *
 * Somebody who wants Memos types `memos`, not `neosmemo/memos`. Hub's search
 * answers in its own relevance order, which on 2026-10-07 put a third-party
 * repackage with 0.1M pulls above the project's own image with 11M — so the
 * results are re-ranked: the exact name typed, official images, then by
 * pulls — with the project's own publisher's pulls counted several times over.
 *
 * Hub's v2 search does not report the "Verified Publisher" badge (its only
 * flags are `is_official` and `is_automated`), so `verified_publisher` is
 * always false today. The field stays in the contract so the UI does not
 * change shape the day a source that knows it is added.
 *
 * A panel with no internet gets an empty list and `offline: true` — never an
 * error, because the picker still accepts a typed reference.
 */
class ImageSearch
{
    /** How much more a pull of the project's own publisher counts, at most. */
    private const PUBLISHER_WEIGHT = 5;

    /**
     * The ramp the weight climbs: none at or below the first, all of it from
     * the second. A ramp, not a step (DS-12) — a floor at 10k let an
     * unrelated 10,001-pull repackage beat the project's own 9,999.
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
                    // More than asked for: re-ranking only helps if the right
                    // answer is in the page at all.
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
     * A weight, never a rank of its own (DS-09). As a rank above pulls it let
     * anybody who registered `kavitaatdesign/kavita` — a handful of pulls —
     * take the preselected first place from `jvmilazz0/kavita` with 11.9M, and
     * the form deploys the first place in one click. Weighted, the project's
     * own image still wins where it is in the same league (Umami: 181k × 5
     * beats a 605k repackage), and a repository with next to no pulls gets
     * next to no boost, so registering a matching name buys nothing.
     *
     * The weight grows with the pulls between BOOST_FROM and BOOST_FULL, so
     * the result still grows with every pull and there is no count at which
     * one more pull jumps a repository past another.
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
     * Whether the publisher looks like the project: the name searched for is
     * in the namespace and IS the repository, as in `umamisoftware/umami`.
     *
     * The repository is matched whole (DS-12). Matched as a substring, every
     * accessory the vendor publishes was weighted as the project:
     * `grafana/grafana-image-renderer` reached third place on "grafana".
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
