<?php

namespace App\Services\Server\Applications\Installers;

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
        $this->cloneInto(
            $application,
            (string) config('server.installers.uptimekuma.repository'),
            (string) config('server.installers.uptimekuma.branch'),
            $documentRoot,
        );

        // `npm run setup` is upstream's own script: install without dev
        // dependencies, then build the frontend. Running `npm ci` here instead
        // would leave the app with no built assets and a blank page.
        $this->runWithNode('install_app', $application, ['npm', 'run', 'setup'], $documentRoot);
    }
}
