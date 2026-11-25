<?php

namespace App\Services\Server\Docker\Images;

use App\Models\Registry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The versions of an image worth offering, and the one to preselect.
 *
 * `latest` is the worst default to deploy (the site silently changes major version after a pull),
 * so a real version number is recommended whenever the image publishes one. Moving tags are
 * listed after the numbered ones, not hidden.
 *
 * Hub images come from Hub's API (with update dates); everything else from `tags/list` (names only).
 */
class ImageTags
{
    /**
     * Words that make a tag a pre-release or a moving target.
     *
     * A tag is read as words, never substrings: `3.2.1-nextcloud` must not read as "next" nor
     * `-devuan` as "dev". Words end at `- . _ +` and at a digit/letter boundary, so `rc1`,
     * `1.2.0-rc.1` and `28-ea+5` carry their marker while `header`, `release` and `bookworm` stay
     * one word. `pre` and `test` are words of their own so they do not match `-prerelease` / `-testing`.
     *
     * `ea` is Java's early-access build. `devel` is kept: it names the development branch.
     * `latest`, `stable`, `lts` and OS or variant names are not markers.
     */
    private const PRERELEASE_WORDS = [
        'ea', 'alpha', 'beta', 'rc', 'dev', 'devel', 'pre', 'prerelease', 'preview', 'nightly',
        'snapshot', 'canary', 'test', 'testing', 'edge', 'unstable', 'next', 'insider',
    ];

    /** Moving tags worth recommending when an image publishes no version, best first. */
    private const STABLE_NAMES = ['latest', 'stable', 'release', 'lts'];

    public function __construct(private RegistryClient $registry) {}

    /**
     * @return array{image: string, recommended: string|null, tags: list<array{name: string, updated_at: string|null, stable: bool, prerelease: bool}>, offline?: bool}
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

            // Nothing to offer: the picker falls back to a typed tag. Only "could not ask" sets `offline`.
            return ['image' => $image->name(), 'recommended' => null, 'tags' => []]
                + ($e->isAboutTheImage() ? [] : ['offline' => true]);
        }

        // `$demoted` is passed along, not kept on the instance: a singleton would share it
        // between images.
        [$tags, $demoted] = $this->rank($raw);
        $recommended = $this->recommended($tags, $demoted);
        $shown = array_slice($tags, 0, $limit);

        // The preselected version must be shown; it can rank past the limit (bare-number aliases
        // sort above dotted releases and `recommended()` skips them), so it takes the last place.
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
     * Hub's API carries update dates; `tags/list` has names only, so an old CalVer tag
     * (`2021.11.28`) would look newer than `2.8.3`. `lscr.io/linuxserver/*` is also on Hub, so it
     * is read from there.
     *
     * A private Hub image is read from the registry: Hub's API wants a session login, not the
     * pull token the panel stores.
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
     * Plain version numbers first, newest first; then variants (`-alpine`); then everything else
     * in the order it came (Hub: most recently updated first).
     *
     * Also returns the date-like versions that lost to a plain version, so `recommended()` can
     * try them last without them leaking into the response.
     *
     * @param  list<array{name: string, updated_at: string|null}>  $raw
     * @return array{0: list<array{name: string, updated_at: string|null, stable: bool, prerelease: bool}>, 1: array<string, true>}
     */
    private function rank(array $raw): array
    {
        $versioned = [];
        $rest = [];

        foreach ($raw as $index => $tag) {
            $version = $this->version($tag['name']);

            if ($version === null) {
                $rest[] = $tag + ['stable' => false, 'prerelease' => $this->isPrerelease($tag['name'])];

                continue;
            }

            $versioned[] = ['tag' => $tag + ['stable' => true, 'prerelease' => false], 'parts' => $version['parts'], 'pure' => $version['pure'], 'index' => $index];
        }

        usort($versioned, function (array $a, array $b): int {
            $length = max(count($a['parts']), count($b['parts']));

            for ($i = 0; $i < $length; $i++) {
                // A missing part sorts after a present one: `0.31.0` before its alias `0.31`.
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

        // Plain numbers before variants (eight variants of one release would fill the picker),
        // and a date that lost to a version after both.
        $current = fn (array $entry): bool => ! isset($demoted[$entry['tag']['name']]);

        usort($versioned, fn (array $a, array $b): int => [$b['pure'], $current($b)] <=> [$a['pure'], $current($a)]);

        return [[...array_column($versioned, 'tag'), ...$rest], $demoted];
    }

    /**
     * Tags that look like a date rather than a release, on an image that also publishes
     * releases, when the dates are not the newer of the two.
     *
     * Numerically `2021.11.28` beats `2.8.3`. The scheme pushed most recently wins where the
     * registry gives dates (Hub); without dates the plain version wins. An image that only
     * publishes dates is left alone.
     *
     * @param  list<array{tag: array{name: string, updated_at: string|null, stable: bool, prerelease: bool}, parts: list<int>, pure: bool, index: int}>  $versioned
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
     * `1.27.3`, `v2.1`, `1.27-alpine` and `2.7.6-ls312` are versions; the last two are variants
     * (stable, never preferred over the plain number). `5.0.0-rc1` is a pre-release, and a bare
     * number over four digits (`20260101`, a build counter) is not a version.
     *
     * @return array{parts: list<int>, pure: bool}|null
     */
    private function version(string $tag): ?array
    {
        if (preg_match('/^v?(\d{1,4}(?:\.\d+){0,3})(?:[-_.+](.+))?$/i', $tag, $match) !== 1) {
            return null;
        }

        $suffix = $match[2] ?? '';

        if ($suffix !== '' && $this->isPrerelease($suffix)) {
            return null;
        }

        return ['parts' => array_map('intval', explode('.', $match[1])), 'pure' => $suffix === ''];
    }

    /**
     * A dotted version followed only by a commit hash: `2024.10.22-7ca5933`,
     * `1.4.2-g3f9c2ab`, `v2.1.0+a1b2c3d4`.
     */
    private function commitPinned(string $tag): bool
    {
        return preg_match('/^v?\d{1,4}(?:\.\d+){1,3}[-_.+]g?[0-9a-f]{7,40}$/i', $tag) === 1
            && preg_match('/[a-f]/i', (string) preg_replace('/^.*?[-_.+]g?(?=[0-9a-f]{7,40}$)/i', '', $tag)) === 1;
    }

    /**
     * The newest exact version (`0.31.0` over `0.31` over `0`, so a pin does not drift with an
     * alias). Falls back to `latest`/`stable`, then Hub's most recently pushed tag, then nothing.
     *
     * @param  list<array{name: string, updated_at: string|null, stable: bool, prerelease: bool}>  $tags
     * @param  array<string, true>  $demoted  from `rank()`
     */
    private function recommended(array $tags, array $demoted): ?string
    {
        // `rank()` put the newest, most specific version first. A bare number is tried last:
        // it is usually an alias, and numerically it beats every dotted version.
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

        // A tag carrying its build commit (`2024.10.22-7ca5933`) is still a release, and better
        // than `latest`. Any other variant (`-alpine`) is a different build, so it is not chosen.
        foreach ([false, true] as $last) {
            foreach ($tags as $tag) {
                if (isset($demoted[$tag['name']]) === $last && $tag['stable'] && $this->commitPinned($tag['name'])) {
                    return $tag['name'];
                }
            }
        }

        $names = array_column($tags, 'name');

        foreach (self::STABLE_NAMES as $name) {
            if (in_array($name, $names, true)) {
                return $name;
            }
        }

        // No version and no stable name. Hub's most recently pushed non-pre-release is a fair
        // guess; a registry's `tags/list` is sorted by name, so its first tag is no recommendation.
        // A list of only pre-releases recommends nothing and the picker asks.
        foreach ($tags as $tag) {
            if ($tag['updated_at'] === null) {
                return null;
            }

            if (! $tag['prerelease']) {
                return $tag['name'];
            }
        }

        return null;
    }

    /**
     * Whether a tag names a pre-release or a moving development build.
     *
     * Read word by word (see PRERELEASE_WORDS). A run of 7 or more hex digits
     * is a commit hash (`2024.10.22-3ea5f2c`) and is not split at its
     * digit/letter boundaries, or the `ea` inside it would read as early access.
     */
    private function isPrerelease(string $tag): bool
    {
        foreach (preg_split('/[^a-z0-9]+/i', $tag, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $segment) {
            if (preg_match('/^g?[0-9a-f]{7,40}$/i', $segment) === 1) {
                continue;
            }

            foreach (preg_split('/(?<=\d)(?=[a-z])|(?<=[a-z])(?=\d)/i', $segment) ?: [] as $word) {
                if (in_array(strtolower($word), self::PRERELEASE_WORDS, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
