<?php

namespace App\Services\Server\Docker\Images;

use App\Models\Registry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The versions of an image worth offering, and the one to preselect.
 *
 * `latest` is the default everybody lands on and the worst one to deploy: the
 * site silently changes major version on the next restart after a pull. So a
 * real version number is recommended whenever the image publishes one, and
 * the moving tags (`latest`, `edge`, `nightly`, `sha-…`) are listed after the
 * numbered ones rather than hidden — somebody who wants `latest` may have it.
 *
 * Hub images are listed from Hub's own API, which carries an update date and
 * is ordered by it; everything else from the registry's `tags/list`, which
 * carries only names.
 */
class ImageTags
{
    /**
     * Words that make a version-looking tag a pre-release or a moving target.
     *
     * Whole words only (DS-09): unanchored, `3.2.1-nextcloud` read as "next"
     * and `-devuan` as "dev", and real stable variants were listed as
     * pre-releases. A number may follow directly — `rc1`, `beta2`. The longer
     * forms are words of their own (DS-12): anchored, `pre` and `test` no
     * longer reached `-prerelease` and `-testing`.
     */
    private const UNSTABLE = '/(?:^|[-_.+])(?:alpha|beta|rc|dev|devel|pre|prerelease|preview|nightly|snapshot|canary|test|testing|edge|unstable|next|insider)(?=\d|[-_.+]|$)/i';

    /** Moving tags worth recommending when an image publishes no version, best first. */
    private const STABLE_NAMES = ['latest', 'stable', 'release', 'lts'];

    public function __construct(private RegistryClient $registry) {}

    /**
     * @return array{image: string, recommended: string|null, tags: list<array{name: string, updated_at: string|null, stable: bool}>, offline?: bool}
     */
    public function list(ImageReference $image, ?Registry $credential, int $limit): array
    {
        if ($credential !== null && ! $image->matches($credential)) {
            $credential = null;
        }

        $cacheKey = 'docker-image-tags:'.sha1($image->name().'|'.($credential?->id ?? '-'));

        try {
            $raw = Cache::remember(
                $cacheKey,
                now()->addMinutes((int) config('server.docker.images.tags_cache_minutes', 10)),
                fn (): array => $this->hubRepository($image, $credential) !== null
                    ? $this->fromHub((string) $this->hubRepository($image, $credential))
                    : array_map(fn (string $name): array => ['name' => $name, 'updated_at' => null], $this->registry->tags($image, $credential)),
            );
        } catch (ImageLookupException $e) {
            if ($e->reason === ImageLookupException::BLOCKED_HOST) {
                throw $e;
            }

            // Nothing to offer is still an answer the picker understands: it
            // falls back to a typed tag. Only "we could not ask" says so.
            return ['image' => $image->name(), 'recommended' => null, 'tags' => []]
                + ($e->isAboutTheImage() ? [] : ['offline' => true]);
        }

        // `$demoted` is handed from one to the other rather than kept on the
        // instance (DS-12): as a property it was state a second caller of
        // `recommended()`, or this class bound as a singleton, would read
        // from somebody else's image.
        [$tags, $demoted] = $this->rank($raw);
        $recommended = $this->recommended($tags, $demoted);
        $shown = array_slice($tags, 0, $limit);

        // The preselected version has to be one the picker can show. It can
        // rank past the limit — bare-number aliases sort above dotted
        // releases, and `recommended()` skips them — so it takes the last
        // place rather than going missing (DS-09).
        if ($recommended !== null && ! in_array($recommended, array_column($shown, 'name'), true)) {
            $match = array_values(array_filter($tags, fn (array $tag): bool => $tag['name'] === $recommended));

            if ($match !== []) {
                $shown = [...array_slice($shown, 0, max(0, $limit - 1)), $match[0]];
            }
        }

        return [
            'image' => $image->name(),
            'recommended' => $recommended,
            'tags' => $shown,
        ];
    }

    /**
     * The Docker Hub repository to list tags from, when there is one.
     *
     * Hub's own API carries an update date; a registry's `tags/list` carries
     * names only, so on GHCR a five-year-old CalVer tag (`2021.11.28`) looks
     * newer than `2.8.3`. LinuxServer publishes every `lscr.io/linuxserver/*`
     * image to Hub as `linuxserver/*` too, so those are read from Hub.
     *
     * A private Hub image is read from the registry instead: Hub's API wants a
     * session login, not the pull token the panel stores.
     */
    private function hubRepository(ImageReference $image, ?Registry $credential): ?string
    {
        if ($credential !== null) {
            return null;
        }

        if ($image->isDockerHub()) {
            return $image->repository;
        }

        if ($image->registry === 'lscr.io' && str_starts_with($image->repository, 'linuxserver/')) {
            return $image->repository;
        }

        return null;
    }

    /**
     * @return list<array{name: string, updated_at: string|null}>
     */
    private function fromHub(string $repository): array
    {
        try {
            $response = Http::connectTimeout((int) config('server.docker.images.connect_timeout', 5))
                ->timeout((int) config('server.docker.images.timeout', 10))
                ->acceptJson()
                ->get('https://hub.docker.com/v2/repositories/'.$repository.'/tags', [
                    'page_size' => 100,
                    'ordering' => 'last_updated',
                ]);
        } catch (ConnectionException $e) {
            throw new ImageLookupException(ImageLookupException::UNREACHABLE, $e->getMessage());
        }

        if ($response->status() === 404) {
            throw new ImageLookupException(ImageLookupException::NOT_FOUND);
        }

        if (! $response->successful()) {
            throw new ImageLookupException(
                $response->status() === 429 ? ImageLookupException::RATE_LIMITED : ImageLookupException::UNREACHABLE,
                'hub answered '.$response->status(),
            );
        }

        $tags = [];

        foreach ((array) $response->json('results', []) as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || ($row['tag_status'] ?? 'active') !== 'active') {
                continue;
            }

            $tags[] = ['name' => $row['name'], 'updated_at' => is_string($row['last_updated'] ?? null) ? $row['last_updated'] : null];
        }

        return $tags;
    }

    /**
     * Plain version numbers first, newest first; then variants (`-alpine`),
     * newest first; then everything else in the order it came (Hub: most
     * recently updated first).
     *
     * Returned beside the list: the versions read as dates (`2021.11.28`)
     * that lost to a plain version on the same image, so `recommended()` can
     * try them last without them leaking into the response.
     *
     * @param  list<array{name: string, updated_at: string|null}>  $raw
     * @return array{0: list<array{name: string, updated_at: string|null, stable: bool}>, 1: array<string, true>}
     */
    private function rank(array $raw): array
    {
        $versioned = [];
        $rest = [];

        foreach ($raw as $index => $tag) {
            $version = $this->version($tag['name']);

            if ($version === null) {
                $rest[] = $tag + ['stable' => false];

                continue;
            }

            $versioned[] = ['tag' => $tag + ['stable' => true], 'parts' => $version['parts'], 'pure' => $version['pure'], 'index' => $index];
        }

        usort($versioned, function (array $a, array $b): int {
            $length = max(count($a['parts']), count($b['parts']));

            for ($i = 0; $i < $length; $i++) {
                // A missing part sorts AFTER a present one, so `0.31.0` comes
                // before `0.31` — the exact version first, its floating alias next.
                $x = $a['parts'][$i] ?? -1;
                $y = $b['parts'][$i] ?? -1;

                if ($x !== $y) {
                    return $y <=> $x;
                }
            }

            return $a['index'] <=> $b['index'];
        });

        $demoted = [];

        foreach ($this->datedLosers($versioned) as $name) {
            $demoted[$name] = true;
        }

        // Plain numbers before variants: `1.31.6-trixie-perl` is a real
        // version, but eight variants of one release would fill the picker.
        // And a date that lost to a version after both.
        $current = fn (array $entry): bool => ! isset($demoted[$entry['tag']['name']]);

        usort($versioned, fn (array $a, array $b): int => [$b['pure'], $current($b)] <=> [$a['pure'], $current($a)]);

        return [[...array_column($versioned, 'tag'), ...$rest], $demoted];
    }

    /**
     * Tags that look like a date rather than a release, on an image that
     * also publishes releases — when the dates are not the newer of the two.
     *
     * Numerically `2021.11.28` beats `2.8.3`, and on an image that once used
     * CalVer that preselected a five-year-old build (DS-09). Which scheme is
     * current is answered by when each was last pushed, where the registry
     * says (Hub does); without dates the plain version wins, because a date
     * tag beside real versions is far more often a leftover than the future.
     * An image that only publishes dates — Home Assistant — is left alone.
     *
     * @param  list<array{tag: array{name: string, updated_at: string|null, stable: bool}, parts: list<int>, pure: bool, index: int}>  $versioned
     * @return list<string>
     */
    private function datedLosers(array $versioned): array
    {
        $dated = [];
        $plain = [];

        foreach ($versioned as $entry) {
            $isDate = count($entry['parts']) >= 2 && $entry['parts'][0] >= 1900 && $entry['parts'][0] <= 2100;

            if ($isDate) {
                $dated[] = $entry['tag'];
            } elseif ($entry['pure']) {
                $plain[] = $entry['tag'];
            }
        }

        if ($dated === [] || $plain === []) {
            return [];
        }

        $newest = fn (array $tags): ?int => array_reduce(
            $tags,
            fn (?int $carry, array $tag): ?int => ($time = strtotime((string) $tag['updated_at'])) === false
                ? $carry
                : max($carry ?? $time, $time),
        );

        $datedAt = $newest($dated);
        $plainAt = $newest($plain);

        if ($datedAt !== null && $plainAt !== null && $datedAt > $plainAt) {
            return [];
        }

        return array_column($dated, 'name');
    }

    /**
     * The numeric parts of a version-looking tag, or null when it is not one.
     *
     * `1.27.3`, `v2.1`, `1.27-alpine` and `2.7.6-ls312` are versions; the last
     * two are "variants" — still stable, never preferred over the plain number.
     * `5.0.0-rc1` is a pre-release, and a bare number longer than four digits
     * (`20260101`, a build counter) is not a version anybody chose.
     *
     * @return array{parts: list<int>, pure: bool}|null
     */
    private function version(string $tag): ?array
    {
        if (preg_match('/^v?(\d{1,4}(?:\.\d+){0,3})(?:[-_.+](.+))?$/i', $tag, $match) !== 1) {
            return null;
        }

        $suffix = $match[2] ?? '';

        if ($suffix !== '' && preg_match(self::UNSTABLE, $suffix) === 1) {
            return null;
        }

        return ['parts' => array_map('intval', explode('.', $match[1])), 'pure' => $suffix === ''];
    }

    /**
     * The newest exact version — `0.31.0` over `0.31` over `0`, so a user
     * pinned to it gets the release they saw, not whatever the alias points
     * at next month. Falls back to `latest`/`stable`, then to Hub's most
     * recently pushed tag, then to nothing.
     *
     * @param  list<array{name: string, updated_at: string|null, stable: bool}>  $tags
     * @param  array<string, true>  $demoted  from `rank()`
     */
    private function recommended(array $tags, array $demoted): ?string
    {
        // `rank()` already put the newest, most specific version first. A bare
        // number is tried last: it is usually an alias, not a release, and
        // ranked numerically it beats every dotted one — code-server's `39`
        // (its Fedora 39 build) sorted above `4.140.0`.
        foreach ([false, true] as $last) {
            foreach ([2, 1] as $minParts) {
                foreach ($tags as $tag) {
                    if (isset($demoted[$tag['name']]) !== $last) {
                        continue;
                    }

                    $version = $tag['stable'] ? $this->version($tag['name']) : null;

                    if (($version['pure'] ?? false) && count($version['parts']) >= $minParts) {
                        return $tag['name'];
                    }
                }
            }
        }

        $names = array_column($tags, 'name');

        foreach (self::STABLE_NAMES as $name) {
            if (in_array($name, $names, true)) {
                return $name;
            }
        }

        // Nothing is a version and nothing says it is the stable one. Hub
        // lists the most recently pushed first, which is a reasonable guess
        // unless it is a pre-release; a registry's `tags/list` is sorted by
        // NAME, and the alphabetically first tag is no recommendation at all —
        // it put `alpha` above `stable` (DS-09). The picker then asks.
        $first = $tags[0] ?? null;

        if ($first === null || $first['updated_at'] === null || preg_match(self::UNSTABLE, '-'.$first['name']) === 1) {
            return null;
        }

        return $first['name'];
    }
}
