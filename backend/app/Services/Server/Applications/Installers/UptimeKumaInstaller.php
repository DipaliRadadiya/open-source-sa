<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;

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
     * The highest `x.y.z` tag the repository publishes.
     *
     * `git ls-remote` rather than GitHub's releases API, for two reasons. It
     * needs no token and has no rate limit — the API allows 60 requests an
     * hour per IP unauthenticated, which a busy panel would exhaust and then
     * fail installs for an hour. And it asks the same host the very next step
     * clones from, so there is no second service to be up.
     *
     * **Pre-releases are excluded by the anchored pattern**, not by trusting a
     * flag: `2.0.0-beta.3` does not match `x.y.z$`. This matters more than it
     * looks — Uptime Kuma spent well over a year publishing 2.x betas, and
     * "newest tag" without this would have installed one on every site.
     *
     * A failure here is fatal rather than falling back to a pinned version.
     * Installing something a year old while the user asked for the latest is
     * the failure mode this whole change exists to remove, and quietly doing
     * it after a lookup fails would put it back in a place nobody looks. The
     * next step clones from the same host anyway, so there is nothing this
     * refusal costs that the clone would not have cost a second later.
     */
    private function latestStableTag(Application $application, string $repository): string
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

        usort($tags, fn (string $a, string $b): int => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));

        return $tags[0];
    }
}
