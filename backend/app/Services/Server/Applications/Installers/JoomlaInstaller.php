<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Joomla — content management system.
 *
 * Differences from the other installers:
 *
 *  - **Its archive is flat** (entries start at `administrator/`), so `--strip-components=1`
 *    would throw away the top-level directories.
 *  - **There is no stable "latest" download URL**; the current release has to be looked up.
 *  - **Passwords are prompted admin first, then database** (opposite of Nextcloud). Reversed,
 *    the install succeeds with the two swapped and the user is locked out.
 */
class JoomlaInstaller extends AbstractPhpInstaller
{
    public function siteType(): string
    {
        return 'joomla';
    }

    /**
     * The package extracts straight into the web root.
     */
    protected function stripComponents(): int
    {
        return 0;
    }

    /**
     * PostgreSQL last, so MySQL stays the first available engine.
     *
     * Per Joomla's `installation/forms/setup.xml`: `db_type` supports `mysql,mysqli,pgsql`.
     *
     * @return array<int, string>
     */
    public function acceptedEngines(): array
    {
        return ['mysql', 'mariadb', 'postgresql'];
    }

    /**
     * PostgreSQL 12 is the minimum Joomla itself enforces (14 is only "supported"), so refusing
     * below 12 never blocks an install Joomla would complete.
     *
     * @return array<string, string>
     */
    public function minimumEngineVersions(): array
    {
        return ['postgresql' => '12'];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $settings = $application->installSettings();

        $this->downloadAndExtract($application, null, $documentRoot);

        // Both passwords are left out and answered at the prompt: on the command line `ps`
        // would show them to every user on the machine.
        $this->runAsSiteUser('install_app', $application, [
            ...$this->phpCommand($application), 'installation/joomla.php', 'install',
            '--site-name='.($settings['site_name'] ?? $application->name),
            '--admin-user='.($settings['admin_name'] ?? 'Administrator'),
            '--admin-username='.($settings['admin_user'] ?? 'admin'),
            '--admin-email='.($settings['admin_email'] ?? ''),
            // The engine the site was actually given, not a configured default.
            '--db-type='.$this->dbType($context),
            '--db-host='.$this->hostWithPort($context),
            '--db-user='.(string) $context['db_user'],
            '--db-name='.(string) $context['database'],
            '--db-prefix='.$this->tablePrefix($settings),
            '--db-encryption=0',
            // Empty: served from the document root itself; omitted, Joomla would prompt.
            '--public-folder=',
        ],
            // Asked for in this order — admin, then database.
            ($settings['admin_password'] ?? '')."\n".$context['db_password']."\n",
            $documentRoot,
        );
    }

    /**
     * Ask Joomla which release is current (downloads carry the version in the filename, so a
     * hardcoded URL would 404 on the next release).
     *
     * @throws ProvisioningFailedException
     */
    protected function downloadUrl(): string
    {
        $configured = (string) config('server.installers.joomla.download_url', '');

        if ($configured !== '') {
            return $configured;
        }

        // An unreachable release host gets its own reason, not a bare "download failed".
        try {
            $response = Http::timeout(15)->acceptJson()
                ->get((string) config('server.installers.joomla.releases_api'));
        } catch (ConnectionException) {
            throw new ProvisioningFailedException('download', (string) Str::uuid(), 'download_unreachable');
        }

        $url = collect($response->successful() ? $response->json('assets') ?? [] : [])
            ->pluck('browser_download_url')
            ->first(fn ($candidate) => is_string($candidate)
                && str_ends_with($candidate, '-Stable-Full_Package.tar.gz'));

        if (! is_string($url)) {
            // Stop rather than unpack something that isn't Joomla into a live web root.
            throw new ProvisioningFailedException('download', (string) Str::uuid(), $response->successful() ? 'release_not_found' : 'download_unreachable');
        }

        return $url;
    }

    /**
     * The driver name Joomla's installer expects: `mysqli` for MySQL and MariaDB, `pgsql` for
     * PostgreSQL.
     *
     * @param  array<string, mixed>  $context
     */
    private function dbType(array $context): string
    {
        return ($context['engine'] ?? '') === 'postgresql' ? 'pgsql' : 'mysqli';
    }

    /**
     * A per-site table prefix, as Joomla's own installer generates.
     *
     * @param  array<string, mixed>  $settings
     */
    private function tablePrefix(array $settings): string
    {
        $prefix = (string) ($settings['table_prefix'] ?? '');

        // A letter first: Joomla refuses a prefix starting with a digit.
        return $prefix !== '' ? $prefix : chr(random_int(ord('a'), ord('z'))).Str::lower(Str::random(4)).'_';
    }
}
