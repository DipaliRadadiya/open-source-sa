<?php

namespace App\Services\Server\Applications\Installers;

use App\Models\Application;

/**
 * Nextcloud — file sync and share.
 *
 * Installed with `occ maintenance:install`. What it demands that other installers do not:
 *
 *  - It must run from Nextcloud's own directory (a PHP fatal otherwise), so the working
 *    directory is set on the process.
 *  - Passwords are prompted on stdin (database first, then admin), never passed as
 *    `--database-pass`/`--admin-pass`: `ps` would show them to every user.
 *  - The data directory lives outside the web root, or every upload would be a URL.
 *  - The domain must be in `trusted_domains`, or every request gets a refusal page.
 */
class NextcloudInstaller extends AbstractPhpInstaller
{
    public function siteType(): string
    {
        return 'nextcloud';
    }

    /**
     * PostgreSQL last, so MySQL stays the first available engine and no existing server changes
     * the database it gives a new Nextcloud.
     *
     * @return array<int, string>
     */
    public function acceptedEngines(): array
    {
        return ['mysql', 'mariadb', 'postgresql'];
    }

    /**
     * Nextcloud supports PostgreSQL 14 through 18.
     *
     * @return array<string, string>
     */
    public function minimumEngineVersions(): array
    {
        return ['postgresql' => '14'];
    }

    /**
     * Upstream publishes bzip2 and zip only. Zip, because lean images have `tar` without a bzip2
     * helper, while `unzip` is already on BinariesCheck's REQUIRED list.
     */
    protected function archiveFormat(): string
    {
        return 'zip';
    }

    /** The zip wraps everything in `nextcloud/`; the tarball did not. */
    protected function archiveRoot(): ?string
    {
        return 'nextcloud';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {

        $this->downloadAndExtract(
            $application,
            (string) config('server.installers.nextcloud.download_url'),
            $documentRoot,
        );

        $dataDir = dirname($documentRoot).'/nextcloud-data';
        $this->run('configure', ['mkdir', '-p', $dataDir], $application);
        $this->run('configure', [
            'chown', '-R', "{$application->systemUser->username}:{$application->systemUser->username}", $dataDir,
        ], $application);
        // Only the site user may read what users uploaded.
        $this->run('configure', ['chmod', '0750', $dataDir], $application);

        $php = $this->phpCommand($application);

        // Both passwords are omitted so occ prompts; the answers go in on stdin in its order:
        // database, then admin.
        $this->runAsSiteUser('install_app', $application, [
            ...$php, 'occ', 'maintenance:install',
            '--database', $this->databaseDriver($context),
            '--database-host', (string) ($context['db_host'] ?? '127.0.0.1'),
            ...$this->portOption($context),
            '--database-name', (string) $context['database'],
            '--database-user', (string) $context['db_user'],
            '--admin-user', (string) ($application->installSettings()['admin_user'] ?? 'admin'),
            '--admin-email', (string) ($application->installSettings()['admin_email'] ?? ''),
            '--data-dir', $dataDir,
        ], $context['db_password']."\n".($application->installSettings()['admin_password'] ?? '')."\n", $documentRoot);

        // From the CLI Nextcloud has no request to learn the hostname from and trusts only
        // localhost; without this the site refuses everyone.
        $this->runAsSiteUser('trust_domain', $application, [
            ...$php, 'occ', 'config:system:set', 'trusted_domains', '1',
            '--value='.$application->domain,
        ], null, $documentRoot);

        // Nextcloud chmods config.php to this on every write; its default 0640 is group-readable,
        // and on OpenLiteSpeed the web server account is in every site user's group. An integer,
        // handed to `chmod()` as is.
        $this->runAsSiteUser('configure', $application, [
            ...$php, 'occ', 'config:system:set', 'configfilemode',
            '--value='.octdec($this->ownership->secretFileMode($application)), '--type=integer',
        ], null, $documentRoot);

        // Background jobs and generated links need to know the site's own
        // address; from the CLI there is nothing to infer it from.
        $this->runAsSiteUser('trust_domain', $application, [
            ...$php, 'occ', 'config:system:set', 'overwrite.cli.url',
            '--value='.$application->url(),
        ], null, $documentRoot);

        // System cron for background jobs; the default (AJAX) only runs them while a page is open.
        $this->runAsSiteUser('schedule_cron', $application, [...$php, 'occ', 'background:cron'], null, $documentRoot);
        $this->scheduleCron($application, $documentRoot, 'cron.php', '*/5 * * * *');
    }

    /**
     * The value for `--database`.
     *
     * Must follow the engine the site was given: the config value alone would hand a PostgreSQL
     * database the string `mysql` and occ's install would die. PostgreSQL branches; everything
     * else still reads the config (`SERVER_NEXTCLOUD_DATABASE`).
     *
     * @param  array<string, mixed>  $context
     */
    private function databaseDriver(array $context): string
    {
        return ($context['engine'] ?? '') === 'postgresql'
            ? 'pgsql'
            : (string) config('server.installers.nextcloud.database', 'mysql');
    }

    /**
     * `--database-port`, omitted when the port is the driver's default.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function portOption(array $context): array
    {
        $port = $this->enginePort($context);

        return $port === null ? [] : ['--database-port', (string) $port];
    }

    public function syncUrl(Application $application, string $url): void
    {
        $documentRoot = $application->documentRoot();

        $this->runAsSiteUser('sync_url', $application, [
            ...$this->phpCommand($application), 'occ', 'config:system:set', 'overwrite.cli.url',
            '--value='.$url,
        ], null, $documentRoot);
    }
}
