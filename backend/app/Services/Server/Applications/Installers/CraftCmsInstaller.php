<?php

namespace App\Services\Server\Applications\Installers;

use App\Models\Application;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Craft CMS.
 *
 * The first installer that builds its application rather than unpacking one:
 * Craft is distributed through Composer, so there is no tarball to fetch.
 *
 * Its two kinds of secret are handled differently, and only one of them can
 * still be kept off the command line. The database credentials go into `.env`,
 * which Craft reads — so `craft install` is never told them, and that remains
 * true.
 *
 * The administrator's password used to be omitted from the options so Craft
 * would prompt, on the reasoning that Yii's prompt is a plain read from stdin
 * and the answer could be piped in. That stopped being true: with stdin not a
 * TTY the prompt is never made, the password is taken as empty, and the
 * install dies on Craft's own validation of a value nobody supplied —
 *
 *     Invalid options:
 *      --password: New Password should contain at least 6 characters.
 *
 * naming an option the command line did not contain. So it is passed as an
 * option, which puts it in `ps` for the life of the process. Same deliberate
 * exception as Akaunting and PrestaShop, for the same reason: it is the only
 * route the tool still supports.
 *
 * Craft also serves from `web/` rather than the site root. Pointing the web
 * server at the root would publish the application's own source, `.env`
 * included.
 */
class CraftCmsInstaller extends AbstractPhpInstaller
{
    public function siteType(): string
    {
        return 'craftcms';
    }

    /**
     * PostgreSQL last, so the first available engine on an existing server is
     * still MySQL and no site the panel already makes moves database.
     *
     * Naming it here is only half the work: `CRAFT_DB_DRIVER` in the `.env`
     * below has to say `pgsql` as well, or the site is handed a PostgreSQL
     * database and told to speak MySQL — which fails inside Craft's own
     * install, past the point where this list could have said anything.
     *
     * @return array<int, string>
     */
    public function acceptedEngines(): array
    {
        return ['mysql', 'mariadb', 'postgresql'];
    }

    /**
     * Craft 5's requirements say "PostgreSQL 13+", and 16+ under recommended.
     * The minimum is what goes here: refusing a 13 that Craft documents as
     * supported would be the panel inventing a requirement, which is the
     * failure a version gate is most likely to cause.
     *
     * 📌 Deliberately 13 and not the 14 Moodle and Nextcloud name. One shared
     * floor would be less code and would refuse installs that work.
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
        $settings = $application->settings ?? [];
        // `web/` is the document root; Craft itself lives in the directory
        // above it, and that is where its commands must run.
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

        // `--password` is left out on purpose: Craft then asks, and Yii's
        // prompt reads stdin.
        $this->runAsSiteUser('install_app', $application, [
            $this->phpBinary($application), 'craft', 'install',
            '--username='.($settings['admin_user'] ?? 'admin'),
            '--email='.($settings['admin_email'] ?? ''),
            '--site-name='.($settings['site_name'] ?? $application->name),
            '--site-url='.$application->url(),
            '--language='.($settings['language'] ?? 'en-US'),
            // On argv, not on stdin — see the class note. Required with a
            // ten-character minimum by the site type, so an empty value here
            // means the setting never arrived rather than a user choosing one.
            '--password='.($settings['admin_password'] ?? ''),
        ], null, $projectRoot);
    }

    /**
     * The value of `CRAFT_DB_DRIVER`, which Craft validates against its own
     * two constants.
     *
     * Read from `craftcms/cms` `src/config/DbConfig.php`: `DRIVER_MYSQL` is
     * `mysql` and `DRIVER_PGSQL` is `pgsql`. `mysql` covers MariaDB too —
     * Craft has no third constant for it — so only PostgreSQL branches.
     *
     * `CRAFT_DB_SCHEMA=public` was already in the `.env` template and is
     * PostgreSQL's own default; MySQL ignores it, which is why it could sit
     * there unused until now.
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

        // Shared with Statamic's `APP_URL`, which is the same edit against a
        // different key — Craft had the only copy of it.
        $changed = $this->setEnvValue($application, $path, 'PRIMARY_SITE_URL', $url);

        if ($changed) {
            $this->runAsSiteUser('sync_url', $application, [
                $this->phpBinary($application), 'craft', 'clear-caches/all', '--interactive=0',
            ], null, $projectRoot);
        }
    }
}
