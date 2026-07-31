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
 * Its secrets are kept off the command line in two different ways, because it
 * has two kinds. The database credentials go into `.env`, which Craft reads —
 * so `craft install` is never told them. The administrator's password is
 * omitted from the options, which makes Craft prompt for it; Yii's prompt is
 * a plain read from stdin, so the answer can be piped in.
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
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): array
    {
        $settings = $application->settings ?? [];
        // `web/` is the document root; Craft itself lives in the directory
        // above it, and that is where its commands must run.
        $projectRoot = dirname($documentRoot);
        $steps = [];

        $this->run('download', [
            (string) config('server.composer_binary', 'composer'),
            'create-project', 'craftcms/craft', $projectRoot,
            '--no-interaction', '--no-scripts', '--no-progress',
        ], $application);
        $steps[] = 'download';

        $this->run('extract', [
            'chown', '-R', "{$application->systemUser->username}:{$application->systemUser->username}", $projectRoot,
        ], $application);
        $steps[] = 'extract';

        // Craft reads these; they are never passed to a command.
        $this->writeSecretFile($application, "{$projectRoot}/.env", View::make('server.apps.craftcms.env', [
            'appId' => 'CraftCMS--'.Str::uuid(),
            'securityKey' => Str::random(32),
            // mysql covers MySQL and MariaDB, which is every SQL engine
            // the panel supports.
            'driver' => 'mysql',
            'host' => $context['db_host'] ?? '127.0.0.1',
            'port' => $context['db_port'] ?? 3306,
            'database' => $context['database'],
            'username' => $context['db_user'],
            'password' => $context['db_password'],
            'siteUrl' => 'https://'.$application->domain,
        ])->render());
        $steps[] = 'configure';

        // `--password` is left out on purpose: Craft then asks, and Yii's
        // prompt reads stdin.
        $this->runAsSiteUser('install_app', $application, [
            $this->phpBinary($application), 'craft', 'install',
            '--username='.($settings['admin_user'] ?? 'admin'),
            '--email='.($settings['admin_email'] ?? ''),
            '--site-name='.($settings['site_name'] ?? $application->name),
            '--site-url=https://'.$application->domain,
            '--language='.($settings['language'] ?? 'en-US'),
        ], ($settings['admin_password'] ?? '')."\n", $projectRoot);
        $steps[] = 'install_app';

        return $steps;
    }
}
