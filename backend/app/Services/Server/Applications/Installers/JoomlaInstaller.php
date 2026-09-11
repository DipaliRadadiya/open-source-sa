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
     * PostgreSQL last, so MySQL stays the first available engine and nothing
     * changes for a server that already makes Joomla sites.
     *
     * Taken from Joomla's own `installation/forms/setup.xml`, where the
     * `db_type` field declares `supported="mysql,mysqli,pgsql"` — so `pgsql`
     * is a value its installer accepts, not a guess.
     *
     * @return array<int, string>
     */
    public function acceptedEngines(): array
    {
        return ['mysql', 'mariadb', 'postgresql'];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $settings = $application->settings ?? [];

        $this->downloadAndExtract($application, null, $documentRoot);

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
            // The engine the site was actually given, not a configured default
            // that could disagree with it. mysqli speaks to both SQL engines
            // the panel had until PostgreSQL, which is why this was a literal.
            '--db-type='.$this->dbType($context),
            '--db-host='.$this->dbHost($context),
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
     * The driver name Joomla's installer expects.
     *
     * `mysqli` covers MySQL and MariaDB alike; `pgsql` is PostgreSQL's, per
     * the `supported` list on its own `db_type` field.
     *
     * @param  array<string, mixed>  $context
     */
    private function dbType(array $context): string
    {
        return ($context['engine'] ?? '') === 'postgresql' ? 'pgsql' : 'mysqli';
    }

    /**
     * `--db-host`, carrying the port for PostgreSQL only.
     *
     * 🔴 **Joomla's CLI has no `--db-port` option.** Its setup form defines no
     * `db_port` field at all; the port travels inside the host as
     * `host:port`, which is the only channel there is.
     *
     * PostgreSQL-only on purpose (operator, 2026-09-11). Every Joomla site the
     * panel has ever made was given a bare host, and MySQL's gap is real but
     * old: a MySQL on a non-default port has always been written a config
     * pointing at 3306. Widening this to every engine would touch the install
     * path of every existing Joomla site to fix a case nobody has reported,
     * so that half is filed as its own task — Moodle has the same gap.
     *
     * Here it is new ground, so it starts correct.
     *
     * @param  array<string, mixed>  $context
     */
    private function dbHost(array $context): string
    {
        $host = (string) ($context['db_host'] ?? '127.0.0.1');

        if (($context['engine'] ?? '') !== 'postgresql') {
            return $host;
        }

        $port = (int) ($context['db_port'] ?? 0);

        // A port we were not told is left off rather than guessed: Joomla then
        // uses PostgreSQL's own default, which is what a guess would have
        // written anyway, without claiming to know it.
        return $port > 0 ? "{$host}:{$port}" : $host;
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
