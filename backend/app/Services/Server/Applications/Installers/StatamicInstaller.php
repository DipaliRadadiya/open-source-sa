<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\ApplicationUpdater;
use App\Models\Application;
use App\Services\Server\Applications\Installers\Concerns\ResolvesUpdates;

/**
 * Statamic — flat-file CMS.
 *
 * It stores content in files (no database) and is a Laravel application, so it serves from
 * `public/`: pointing the web server at the project root would publish its source and `.env`.
 *
 * It is the one installer that puts a password on a command line. `make:user` only takes
 * `--password` when given an email argument; without one it prompts through Laravel Prompts,
 * which refuses to read a pipe, so there is no stdin path. Writing Statamic's user file ourselves
 * would depend on its private storage format; a password visible in `ps` for a second is the
 * smaller risk.
 */
class StatamicInstaller extends AbstractPhpInstaller implements ApplicationUpdater
{
    use ResolvesUpdates;

    public function siteType(): string
    {
        return 'statamic';
    }

    /** Content lives in files; a database would sit empty. */
    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $settings = $application->installSettings();
        // `public/` is served; Statamic itself lives above it.
        $projectRoot = dirname($documentRoot);

        $this->composerCreateProject($application, 'statamic/statamic', $projectRoot, $documentRoot, [
            '--no-interaction', '--no-progress',
        ]);

        // `.env.example` ships `APP_URL=http://localhost:8000`, which Laravel builds password
        // resets, mail, sitemaps and Statamic's URLs from. Set it before `make:user`, so the
        // first login lands on the real site.
        $this->setEnvValue($application, $application->envPath(), 'APP_URL', $application->url());

        // The same file ships APP_ENV=local and APP_DEBUG=true; debug mode on a live site shows
        // stack traces, paths and config values to visitors.
        $this->setEnvValue($application, $application->envPath(), 'APP_ENV', 'production');
        $this->setEnvValue($application, $application->envPath(), 'APP_DEBUG', 'false');

        // The email argument selects the non-interactive path; `--password` is on the command line
        // because there is no other way in (see the class note).
        $this->runAsSiteUser('install_app', $application, array_filter([
            ...$this->phpCommand($application), 'please', 'make:user',
            (string) ($settings['admin_email'] ?? ''),
            '--super',
            '--password='.($settings['admin_password'] ?? ''),
        ]), null, $projectRoot);
    }

    /**
     * Keep `APP_URL` on the domain the site is actually reached at.
     *
     * Called when the primary domain changes or a certificate makes the site `https`. The config
     * cache is cleared only when something changed: a compiled `bootstrap/cache/config.php` is
     * read instead of `.env`, so the old URL would keep being served.
     */
    public function syncUrl(Application $application, string $url): void
    {
        $changed = $this->setEnvValue($application, $application->envPath(), 'APP_URL', $url);

        if ($changed) {
            $this->runAsSiteUser('sync_url', $application, [
                ...$this->phpCommand($application), 'artisan', 'config:clear',
            ], null, $application->codePath());
        }
    }

    // ---- One-click update (ApplicationUpdater) ---------------------------

    public function installedVersion(Application $application): ?string
    {
        return $this->composerInstalledVersion($application, $application->codePath(), 'statamic/cms');
    }

    public function availableVersions(Application $application, string $installed): array
    {
        $versions = $this->packagistVersions('statamic/cms');

        return ['latest' => $this->highestInLine($versions, $installed), 'newest' => $this->highest($versions)];
    }

    /**
     * `composer update statamic/cms`, which keeps the project's own constraint (`^6.0`) and so
     * stays inside the major. Statamic's update scripts run from its Composer hooks.
     */
    public function update(Application $application, string $from, string $to): void
    {
        $projectRoot = $application->codePath();

        $this->runAsSiteUser('update_app', $application, array_merge(
            $this->shim->envPrefixFor($application),
            [
                (string) config('server.composer_binary', 'composer'),
                'update', 'statamic/cms', '--with-all-dependencies', '--no-interaction', '--no-progress',
            ],
        ), null, $projectRoot);

        $this->runAsSiteUser('clear_cache', $application, [
            ...$this->phpCommand($application), 'please', 'stache:refresh',
        ], null, $projectRoot);
    }
}
