<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use Illuminate\Support\Facades\Http;

/**
 * Uptime Kuma — self-hosted uptime monitoring.
 *
 * Distributed as a git repository, not a release archive: upstream's own
 * non-Docker instructions are `git clone` then `npm run setup`, which installs
 * dependencies and builds the frontend in one step. There is no tarball to
 * fetch, so this does not use the shared download helper.
 *
 * It stores everything in SQLite under `data/` inside the application, so it
 * needs no database and no credentials written anywhere.
 *
 * **No admin account is created here.** Uptime Kuma has no setup CLI — the
 * first person to open the site creates the administrator, and until they do
 * anyone can. That is upstream's design, so the panel surfaces it in the site
 * type's tagline rather than pretending the site is finished.
 *
 * It reads `PORT`, which the unit already sets to the port the panel
 * allocated, so the process and the reverse proxy cannot disagree.
 */
class UptimeKumaInstaller extends AbstractNodeInstaller
{
    public function siteType(): string
    {
        return 'uptimekuma';
    }

    public function startCommand(Application $application, string $documentRoot): ?string
    {
        return 'node server/server.js';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $repository = (string) config('server.installers.uptimekuma.repository');

        $this->cloneInto($application, $repository, $this->ref($application, $repository), $documentRoot);

        // `npm run setup` is upstream's own script: install without dev
        // dependencies, then build the frontend. Running `npm ci` here instead
        // would leave the app with no built assets and a blank page.
        $this->runWithNode('install_app', $application, ['npm', 'run', 'setup'], $documentRoot);
    }

    /**
     * The git ref to clone: an operator's pin, or the newest stable release.
     *
     * The config used to name a tag outright, and that tag was `2.0.0` — while
     * upstream had reached 2.5.3. A hardcoded version in a one-click installer
     * does not stay current; it just stops being noticed, and every site
     * created from it is however far behind the pin has drifted.
     *
     * So the pin becomes an override rather than the policy. Set
     * `SERVER_UPTIME_KUMA_BRANCH` to hold a specific tag or branch; leave it
     * empty and the installer asks the repository what the newest release is.
     */
    private function ref(Application $application, string $repository): string
    {
        $pinned = trim((string) config('server.installers.uptimekuma.branch', ''));

        return $pinned !== '' ? $pinned : $this->latestStableTag($application, $repository);
    }

    /**
     * The newest stable release, asked of the releases API and then of the
     * repository's tags.
     *
     * Two sources because they fail in opposite ways. The API knows what a
     * *release* is — it marks drafts and pre-releases with flags rather than
     * leaving them to be guessed from a tag name — but it allows only 60
     * requests an hour per IP unauthenticated, and a panel that has spent them
     * cannot install anything for the rest of the hour. `ls-remote` has no
     * limit and answers the same host the clone comes from, but it sees tags,
     * and a tag is not a release.
     *
     * So: ask the precise source, fall back to the always-available one. Both
     * answer the same question, and today they agree on 2.5.3.
     */
    private function latestStableTag(Application $application, string $repository): string
    {
        return $this->fromReleasesApi() ?? $this->fromRepositoryTags($application, $repository);
    }

    /**
     * The newest stable release from GitHub's releases API, or null if it
     * could not be asked.
     *
     * **The full list, sorted by version — deliberately not `/releases/latest`.**
     * GitHub defines "latest" as the most recent non-draft, non-pre-release by
     * *date*, and this project back-patches its old line: 1.23.17 was published
     * on 2025-10-20, long after 2.x existed. One more such patch and
     * `/releases/latest` would name a 1.x release as current, and every new
     * site would be built from it. Sorting the list by version cannot make that
     * mistake.
     *
     * `draft` and `prerelease` come from the flags rather than from the shape
     * of the tag, which is the one thing the API does better than the tag list.
     * The `x.y.z` pattern still applies on top, because a release can be named
     * anything at all.
     */
    private function fromReleasesApi(): ?string
    {
        $endpoint = trim((string) config('server.installers.uptimekuma.releases_api', ''));

        if ($endpoint === '') {
            return null;
        }

        try {
            $response = Http::timeout(15)
                // GitHub answers 403 to a request with no User-Agent.
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'control-panel'])
                ->get($endpoint, ['per_page' => 100]);
        } catch (\Throwable) {
            // A DNS failure or a timeout is not a reason to refuse the install
            // while a second source is still standing.
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $tags = collect($response->json())
            ->filter(fn ($release): bool => is_array($release)
                && ($release['draft'] ?? false) === false
                && ($release['prerelease'] ?? false) === false)
            ->pluck('tag_name')
            ->filter(fn ($tag): bool => is_string($tag) && preg_match('/^v?\d+\.\d+\.\d+$/', $tag) === 1)
            ->values()
            ->all();

        if ($tags === []) {
            return null;
        }

        return $this->highest($tags);
    }

    /**
     * The highest `x.y.z` tag the repository publishes.
     *
     * The fallback, and the reason there is one: `ls-remote` needs no token and
     * has no rate limit, so a panel that has exhausted GitHub's hourly API
     * allowance can still install.
     *
     * **Pre-releases are excluded by the anchored pattern**, not by a flag —
     * there are no flags here. `2.0.0-beta.3` does not match `x.y.z$`, which
     * matters more than it looks: this project spent well over a year
     * publishing 2.x betas, and "newest tag" without the anchor would have put
     * one on every site.
     *
     * Failing here is fatal rather than falling back to a pinned version.
     * Installing something a year old while the user asked for the latest is
     * the failure this whole change removes, and doing it quietly after a
     * lookup fails would put it back where nobody looks. The next step clones
     * from the same host anyway, so the refusal costs nothing the clone would
     * not have cost a second later.
     */
    private function fromRepositoryTags(Application $application, string $repository): string
    {
        $result = $this->run(
            'resolve_version',
            ['git', 'ls-remote', '--tags', '--refs', $repository],
            $application,
        );

        $tags = [];

        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            // `--refs` drops the `^{}` peeled duplicates, so each tag appears
            // once. The `v` is optional because upstream has used both forms
            // across its history and a tag list is not the place to be strict.
            if (preg_match('#refs/tags/(v?\d+\.\d+\.\d+)$#', trim($line), $matches) === 1) {
                $tags[] = $matches[1];
            }
        }

        if ($tags === []) {
            throw new ProvisioningFailedException('resolve_version', $result->reference);
        }

        return $this->highest($tags);
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function highest(array $tags): string
    {
        // `version_compare`, never a string sort: '2.10.0' is below '2.9.0' as
        // text and above it as a version, so sorting as text installs the wrong
        // release the first time a minor reaches double digits.
        usort($tags, fn (string $a, string $b): int => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));

        return $tags[0];
    }
}
