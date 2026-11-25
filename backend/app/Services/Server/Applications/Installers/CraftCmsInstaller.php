<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\ApplicationUpdater;
use App\Models\Application;
use App\Services\Server\Applications\Installers\Concerns\ResolvesUpdates;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Craft CMS.
 *
 * Built with Composer rather than unpacked from a tarball. Database credentials go into `.env`,
 * never onto the `craft install` command line. The admin password has to be passed as an option
 * (visible in `ps`): with stdin not a TTY Craft's prompt never runs and the install fails on an
 * empty password. Same exception as Akaunting and PrestaShop.
 *
 * Craft serves from `web/`, not the site root: pointing the web server at the root would
 * publish the application's source and `.env`.
 */
class CraftCmsInstaller extends AbstractPhpInstaller implements ApplicationUpdater
{
    use ResolvesUpdates;

    public function siteType(): string
    {
        return 'craftcms';
    }

    /**
     * PostgreSQL last, so the first available engine on an existing server is still MySQL.
     * `CRAFT_DB_DRIVER` in the `.env` must say `pgsql` too, or Craft is told to speak MySQL to it.
     *
     * @return array<int, string>
     */
    public function acceptedEngines(): array
    {
        return ['mysql', 'mariadb', 'postgresql'];
    }

    /**
     * Craft 5 documents PostgreSQL 13+ as supported (16+ recommended); the minimum goes here.
     * Deliberately 13, not the 14 Moodle and Nextcloud name: a shared floor would refuse
     * installs that work.
     *
     * @return array<string, string>
     */
    public function minimumEngineVersions(): array
    {
        return ['postgresql' => '13'];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $settings = $application->installSettings();
        // `web/` is the document root; Craft's commands run in the directory above it.
        $projectRoot = dirname($documentRoot);

        $this->composerCreateProject($application, 'craftcms/craft', $projectRoot, $documentRoot, [
            '--no-interaction', '--no-scripts', '--no-progress',
        ]);

        // Craft reads these; they are never passed to a command.
        $this->writeSecretFile($application, "{$projectRoot}/.env", View::make('server.apps.craftcms.env', [
            'appId' => 'CraftCMS--'.Str::uuid(),
            'securityKey' => Str::random(32),
            'driver' => $this->driver($context),
            'host' => $context['db_host'] ?? '127.0.0.1',
            'port' => $context['db_port'] ?? 3306,
            'database' => $context['database'],
            'username' => $context['db_user'],
            'password' => $context['db_password'],
            'siteUrl' => $application->url(),
        ])->render());

        $this->runAsSiteUser('install_app', $application, [
            ...$this->phpCommand($application), 'craft', 'install',
            '--username='.($settings['admin_user'] ?? 'admin'),
            '--email='.($settings['admin_email'] ?? ''),
            '--site-name='.($settings['site_name'] ?? $application->name),
            '--site-url='.$application->url(),
            '--language='.($settings['language'] ?? 'en-US'),
            // On argv, not stdin (see the class note). The site type enforces a ten-character
            // minimum, so empty here means the setting never arrived.
            '--password='.($settings['admin_password'] ?? ''),
        ], null, $projectRoot);
    }

    /**
     * The value of `CRAFT_DB_DRIVER`: Craft accepts only `mysql` (also used for MariaDB) or `pgsql`.
     *
     * @param  array<string, mixed>  $context
     */
    private function driver(array $context): string
    {
        return ($context['engine'] ?? '') === 'postgresql' ? 'pgsql' : 'mysql';
    }

    public function syncUrl(Application $application, string $url): void
    {
        $projectRoot = $application->codePath();
        $path = $projectRoot.'/.env';

        // Shared with Statamic's `APP_URL`: the same edit against a different key.
        $changed = $this->setEnvValue($application, $path, 'PRIMARY_SITE_URL', $url);

        if ($changed) {
            $this->runAsSiteUser('sync_url', $application, [
                ...$this->phpCommand($application), 'craft', 'clear-caches/all', '--interactive=0',
            ], null, $projectRoot);
        }
    }

    // ---- One-click update (ApplicationUpdater) ---------------------------

    public function installedVersion(Application $application): ?string
    {
        return $this->composerInstalledVersion($application, $application->codePath(), 'craftcms/cms');
    }

    public function availableVersions(Application $application, string $installed): array
    {
        $versions = $this->packagistVersions('craftcms/cms');

        return ['latest' => $this->highestInLine($versions, $installed), 'newest' => $this->highest($versions)];
    }

    /**
     * Composer, pinned to the target so it cannot cross a major, then
     * `craft up` for the migrations and project config.
     *
     * Not `craft update`: it asks api.craftcms.com for a licence first and fails on a site
     * without one. The Composer route is documented by Craft and needs no licence.
     */
    public function update(Application $application, string $from, string $to): void
    {
        $projectRoot = $application->codePath();

        $this->runAsSiteUser('update_app', $application, array_merge(
            $this->shim->envPrefixFor($application),
            [
                (string) config('server.composer_binary', 'composer'),
                'update', 'craftcms/cms', '--with', 'craftcms/cms:'.$to,
                '--with-all-dependencies', '--no-interaction', '--no-progress',
            ],
        ), null, $projectRoot);

        $this->runAsSiteUser('migrate', $application, [
            ...$this->phpCommand($application), 'craft', 'up', '--interactive=0',
        ], null, $projectRoot);
    }
}
