<?php

namespace App\Services\Server\Applications\Installers;

use App\Models\Application;

/**
 * Nextcloud — file sync and share.
 *
 * Installed with its own `occ maintenance:install`, which upstream documents
 * as the command-line equivalent of the setup wizard. Four things it demands
 * that the other installers do not:
 *
 *  - **It must be run from Nextcloud's own directory.** Upstream is explicit:
 *    run it from anywhere else and it dies with a PHP fatal error. There is no
 *    shell here to `cd` in, so the working directory is set on the process.
 *  - **The passwords are prompted, not passed.** `--database-pass` and
 *    `--admin-pass` would put both on the command line, where `ps` shows them
 *    to every user on the machine. Omitting them makes occ ask on stdin —
 *    database password first, then admin password — which is where they go.
 *  - **The data directory belongs outside the web root.** It holds every
 *    file every user uploads, and under the document root each one is a URL.
 *  - **The domain must be trusted.** Nextcloud refuses to serve a hostname
 *    that is not in `trusted_domains`, so a site installed without this step
 *    answers every request with a refusal page.
 */
class NextcloudInstaller extends AbstractSiteInstaller
{
    public function siteType(): string
    {
        return 'nextcloud';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): array
    {
        $steps = [];

        $this->downloadAndExtract(
            $application,
            (string) config('server.installers.nextcloud.download_url'),
            $documentRoot,
        );
        $steps[] = 'download';
        $steps[] = 'extract';

        $dataDir = dirname($documentRoot).'/nextcloud-data';
        $this->run('configure', ['mkdir', '-p', $dataDir], $application);
        $this->run('configure', [
            'chown', '-R', "{$application->systemUser->username}:{$application->systemUser->username}", $dataDir,
        ], $application);
        // Upstream's hardening guidance: nobody but the site user has any
        // business reading what users have uploaded.
        $this->run('configure', ['chmod', '0750', $dataDir], $application);
        $steps[] = 'configure';

        $php = $this->phpBinary($application);

        // Both passwords are omitted so that occ prompts for them, and both
        // answers go in on stdin in the order it asks: database, then admin.
        $this->runAsSiteUser('install_app', $application, [
            $php, 'occ', 'maintenance:install',
            '--database', (string) config('server.installers.nextcloud.database', 'mysql'),
            '--database-host', (string) ($context['db_host'] ?? '127.0.0.1'),
            '--database-name', (string) $context['database'],
            '--database-user', (string) $context['db_user'],
            '--admin-user', (string) ($application->settings['admin_user'] ?? 'admin'),
            '--admin-email', (string) ($application->settings['admin_email'] ?? ''),
            '--data-dir', $dataDir,
        ], $context['db_password']."\n".($application->settings['admin_password'] ?? '')."\n", $documentRoot);
        $steps[] = 'install_app';

        // Installed from the command line, Nextcloud has no request to learn
        // the hostname from, so it trusts only localhost. Without this the
        // site is reachable and refuses everyone.
        $this->runAsSiteUser('trust_domain', $application, [
            $php, 'occ', 'config:system:set', 'trusted_domains', '1',
            '--value='.$application->domain,
        ], null, $documentRoot);

        // Background jobs and generated links need to know the site's own
        // address; from the CLI there is nothing to infer it from.
        $this->runAsSiteUser('trust_domain', $application, [
            $php, 'occ', 'config:system:set', 'overwrite.cli.url',
            '--value=https://'.$application->domain,
        ], null, $documentRoot);
        $steps[] = 'trust_domain';

        return $steps;
    }

    /**
     * The PHP the site itself runs on, so occ builds the database schema with
     * the same version and extensions that will serve the application.
     */
    private function phpBinary(Application $application): string
    {
        $version = $application->php_version ?: config('server.default_php_version', '8.4');

        return (string) config('server.php_binary_pattern', '/usr/bin/php{version}') === ''
            ? 'php'
            : str_replace('{version}', (string) $version, (string) config('server.php_binary_pattern', '/usr/bin/php{version}'));
    }
}
