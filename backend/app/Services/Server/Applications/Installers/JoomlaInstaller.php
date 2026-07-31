<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Joomla — content management system.
 *
 * Three things this one does differently, each of which would have been a bug
 * had the previous installer's shape simply been copied:
 *
 *  - **Its archive is flat.** The full package's entries start at
 *    `administrator/`, with no wrapping directory, so the usual
 *    `--strip-components=1` would throw away the top-level directories and
 *    scatter their contents across the web root.
 *  - **There is no stable "latest" download URL.** Joomla publishes versioned
 *    filenames, so the current release has to be asked for before anything can
 *    be fetched.
 *  - **The passwords are prompted in the opposite order to Nextcloud's** —
 *    admin first, then database. The order is not a detail: reversed, the
 *    installation succeeds with the two swapped, and the user is locked out of
 *    a site that otherwise looks fine.
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
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): array
    {
        $settings = $application->settings ?? [];
        $steps = [];

        $this->downloadAndExtract($application, null, $documentRoot);
        $steps[] = 'download';
        $steps[] = 'extract';

        // Every option except the two passwords is given here. Joomla prompts
        // for whatever it wasn't told, and prompting is the only way to get a
        // secret in without putting it on the command line, where `ps` shows
        // it to every user on the machine.
        $this->runAsSiteUser('install_app', $application, [
            $this->phpBinary($application), 'installation/joomla.php', 'install',
            '--site-name='.($settings['site_name'] ?? $application->name),
            '--admin-user='.($settings['admin_name'] ?? 'Administrator'),
            '--admin-username='.($settings['admin_user'] ?? 'admin'),
            '--admin-email='.($settings['admin_email'] ?? ''),
            // mysqli covers both MySQL and MariaDB — the engine the site was
            // actually given, not a configured default that could disagree
            // with it.
            // mysqli speaks to both SQL engines the panel supports, so there
            // is nothing to branch on — a helper here would have had two
            // identical arms.
            '--db-type=mysqli',
            '--db-host='.(string) ($context['db_host'] ?? '127.0.0.1'),
            '--db-user='.(string) $context['db_user'],
            '--db-name='.(string) $context['database'],
            '--db-prefix='.$this->tablePrefix($settings),
            '--db-encryption=0',
            // Empty: the site is served from the document root itself. Left
            // out, Joomla would stop and ask about it.
            '--public-folder=',
        ],
            // Asked for in this order — admin, then database.
            ($settings['admin_password'] ?? '')."\n".$context['db_password']."\n",
            $documentRoot,
        );
        $steps[] = 'install_app';

        return $steps;
    }

    /**
     * Ask Joomla which release is current.
     *
     * Their downloads carry the version in the filename and there is no
     * "latest" alias, so a hardcoded URL would rot into a 404 on the next
     * release and take one-click Joomla down with it.
     *
     * @throws ProvisioningFailedException
     */
    protected function downloadUrl(): string
    {
        $configured = (string) config('server.installers.joomla.download_url', '');

        if ($configured !== '') {
            return $configured;
        }

        $response = Http::timeout(15)->acceptJson()
            ->get((string) config('server.installers.joomla.releases_api'));

        $url = collect($response->successful() ? $response->json('assets') ?? [] : [])
            ->pluck('browser_download_url')
            ->first(fn ($candidate) => is_string($candidate)
                && str_ends_with($candidate, '-Stable-Full_Package.tar.gz'));

        if (! is_string($url)) {
            // Better to stop here than to download something that isn't
            // Joomla and unpack it into a live web root.
            throw new ProvisioningFailedException('download', (string) Str::uuid());
        }

        return $url;
    }

    /**
     * A per-site table prefix, as Joomla's own installer generates. It keeps
     * the tables apart if the database is ever shared.
     *
     * @param  array<string, mixed>  $settings
     */
    private function tablePrefix(array $settings): string
    {
        $prefix = (string) ($settings['table_prefix'] ?? '');

        return $prefix !== '' ? $prefix : Str::lower(Str::random(5)).'_';
    }
}
