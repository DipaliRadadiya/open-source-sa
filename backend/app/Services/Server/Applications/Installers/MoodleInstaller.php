<?php

namespace App\Services\Server\Applications\Installers;

use App\Contracts\ApplicationUpdater;
use App\Models\Application;
use App\Services\Server\Applications\Installers\Concerns\ResolvesUpdates;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Moodle — learning management system.
 *
 * Moodle's CLI installers offer no way to pass a secret without putting it on the command
 * line, where `ps` shows it to every user: `install.php` asks fourteen interactive questions,
 * and `install_database.php` requires `--adminpass` with no prompt.
 *
 * So the install is split so that no secret of the user's is ever an argument:
 *
 *  1. `config.php` is written here, carrying the database credentials.
 *  2. `install_database.php` runs with a **throwaway** random admin password, replaced moments
 *     later, so its brief appearance in `ps` discloses nothing.
 *  3. `reset_password.php` sets the password the user chose; its single prompt is answered
 *     reliably on stdin.
 *
 * Moodle's password policy is enforced at validation, not waived: a failed reset would leave
 * the account on a random password nobody knows.
 */
class MoodleInstaller extends AbstractPhpInstaller implements ApplicationUpdater
{
    use ResolvesUpdates;

    /**
     * What Moodle's installer demands (the CLI default is 1000). A hard stop: its environment
     * check reports it as "this test must pass".
     */
    private const MIN_INPUT_VARS = 5000;

    public function siteType(): string
    {
        return 'moodle';
    }

    /**
     * PostgreSQL last, so MySQL stays the first available engine. Moodle distinguishes MySQL
     * from MariaDB (see {@see dbType()}); PostgreSQL needs `dbcollation` dropped, which the
     * config template does.
     *
     * @return array<int, string>
     */
    public function acceptedEngines(): array
    {
        return ['mysql', 'mariadb', 'postgresql'];
    }

    /**
     * Moodle 5.0's floors, from `admin/environment.xml` on `MOODLE_500_STABLE` (the line
     * `server.installers.moodle.download_url` pins).
     *
     * 🔴 MySQL needs an entry: 5.0 raised its minimum to 8.4 and Ubuntu 24.04 ships 8.0, so
     * Moodle would be offered and then refused by its own installer.
     *
     * @return array<string, string>
     */
    public function minimumEngineVersions(): array
    {
        return ['mysql' => '8.4', 'mariadb' => '10.11.0', 'postgresql' => '14'];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $settings = $application->installSettings();

        $this->downloadAndExtract($application, null, $documentRoot);

        // Uploads, caches and sessions: must stay outside the web root, or every submission is a URL.
        $dataDir = dirname($documentRoot).'/moodledata';
        $this->run('configure', ['mkdir', '-p', $dataDir], $application);
        $this->run('configure', [
            'chown', '-R', "{$application->systemUser->username}:{$application->systemUser->username}", $dataDir,
        ], $application);
        $this->run('configure', ['chmod', '0750', $dataDir], $application);

        $isPostgres = ($context['engine'] ?? '') === 'postgresql';

        $this->writeSecretFile($application, "{$documentRoot}/config.php", View::make('server.apps.moodle.config', [
            'dbType' => $this->dbType($context),
            // `dbcollation` is MySQL-only (config-dist.php: remove it for other databases).
            'collation' => $isPostgres ? null : 'utf8mb4_unicode_ci',
            // Empty unless the port differs from the one Moodle's driver assumes.
            'port' => (string) ($this->enginePort($context) ?? ''),
            'host' => $context['db_host'] ?? '127.0.0.1',
            'database' => $context['database'],
            'username' => $context['db_user'],
            'password' => $context['db_password'],
            'prefix' => $settings['table_prefix'] ?? 'mdl_',
            'wwwroot' => $application->url(),
            'dataroot' => $dataDir,
        ])->render());

        $php = $this->phpCommand($application);
        $adminUser = (string) ($settings['admin_user'] ?? 'admin');

        // The password here is deliberately disposable (see the class note).
        // `-d max_input_vars` on the interpreter, not the site's php.ini: the installer refuses
        // to run below 5000 (CLI default 1000), and this avoids editing a php.ini the user may
        // own. The site's own pool needs the setting separately for the browser.
        $this->runAsSiteUser('install_app', $application, [
            ...$php, '-d', 'max_input_vars='.self::MIN_INPUT_VARS,
            'admin/cli/install_database.php',
            '--agree-license',
            '--adminuser='.$adminUser,
            '--adminemail='.($settings['admin_email'] ?? ''),
            '--fullname='.($settings['site_name'] ?? $application->name),
            '--shortname='.($settings['short_name'] ?? Str::limit(Str::slug((string) $application->name), 20, '')),
            '--adminpass='.$this->throwawayPassword(),
        ], null, $documentRoot);

        // The real password, on stdin: with a username, reset_password.php asks for one thing only.
        $this->runAsSiteUser('set_password', $application, [
            ...$php, '-d', 'max_input_vars='.self::MIN_INPUT_VARS,
            'admin/cli/reset_password.php', '--username='.$adminUser,
        ], ($settings['admin_password'] ?? '')."\n", $documentRoot);

        // Every minute: without it Moodle sends no mail and runs no backups or cleanup.
        $this->scheduleCron($application, $documentRoot, 'admin/cli/cron.php', '* * * * *');
    }

    public function syncUrl(Application $application, string $url): void
    {
        $path = $application->documentRoot().'/config.php';

        $this->configMutator->transform($application, $path, function (string $contents) use ($url): string {
            $replacement = '$CFG->wwwroot   = '.var_export($url, true).';';
            $updated = preg_replace(
                '/^\$CFG->wwwroot\s*=\s*[\'\"][^\r\n]*[\'\"];\s*$/m',
                $replacement,
                $contents,
                1,
                $count,
            );

            if (! is_string($updated) || $count !== 1) {
                throw new \RuntimeException('Moodle wwwroot was not found.');
            }

            return $updated;
        });
    }

    /**
     * A password that satisfies Moodle's policy and belongs to nobody; replaced before the
     * site is usable.
     */
    private function throwawayPassword(): string
    {
        return Str::random(24).'aA1!';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function dbType(array $context): string
    {
        // Moodle uses a different driver for MySQL and MariaDB.
        return match ($context['engine'] ?? '') {
            'mariadb' => 'mariadb',
            'postgresql' => 'pgsql',
            default => 'mysqli',
        };
    }

    // ---- One-click update (ApplicationUpdater) ---------------------------

    public function installedVersion(Application $application): ?string
    {
        $file = (string) $this->readSiteFile($application, $application->documentRoot().'/version.php');

        return preg_match('/^\$release\s*=\s*\'(\d+\.\d+(?:\.\d+)?)/m', $file, $match) === 1
            ? $this->threePart($match[1])
            : null;
    }

    /**
     * Moodle's line is the branch (5.0, 4.5), not the major: 5.0 → 5.1 moved the code under
     * `public/`. The newest release of a branch is its highest release tag, not the branch's
     * `version.php` or the `moodle-latest-500` package, which can lag behind.
     */
    public function availableVersions(Application $application, string $installed): array
    {
        $tags = collect($this->getJson('https://api.github.com/repos/moodle/moodle/tags', ['per_page' => 100]) ?? [])
            ->pluck('name')
            ->filter(fn ($tag): bool => is_string($tag) && $this->isStable($tag))
            ->map(fn (string $tag): string => $this->normalizeVersion($tag))
            ->values()
            ->all();

        return ['latest' => $this->highestInLine($tags, $installed, 2), 'newest' => $this->highest($tags)];
    }

    /**
     * New code over the old, then Moodle's own upgrade, under maintenance mode.
     *
     * `admin/cli/upgrade.php` alone only upgrades the database to the code on disk, so new
     * code is downloaded first. `config.php` survives the copy (the package ships only
     * `config-dist.php`); moodledata lives beside the code.
     */
    public function update(Application $application, string $from, string $to): void
    {
        $documentRoot = $application->documentRoot();
        $php = [...$this->phpCommand($application), '-d', 'max_input_vars='.self::MIN_INPUT_VARS];
        // The exact release, so the version installed is the one asked for.
        $url = str_replace(['{branch}', '{version}'], [$this->branchCode($to), $to], (string) config(
            'server.installers.moodle.release_url',
            'https://packaging.moodle.org/stable{branch}/moodle-{version}.tgz',
        ));

        $this->runAsSiteUser('maintenance_on', $application, [...$php, 'admin/cli/maintenance.php', '--enable'], null, $documentRoot);

        try {
            $this->downloadAndExtract($application, $url, $documentRoot);
            $this->runAsSiteUser('upgrade', $application, [...$php, 'admin/cli/upgrade.php', '--non-interactive'], null, $documentRoot);
        } finally {
            // Best effort: a failed upgrade is reported by the step that failed,
            // and Moodle itself refuses to serve a half-upgraded site.
            $this->serverOps->run(
                ['runuser', '-u', $application->systemUser->username, '--', ...$php, 'admin/cli/maintenance.php', '--disable'],
                ['feature' => 'application', 'op' => 'update.maintenance_off', 'application' => $application->id],
                cwd: $documentRoot,
            );
        }
    }

    private function branchCode(string $version): string
    {
        [$major, $minor] = array_pad(explode('.', $this->normalizeVersion($version)), 2, '0');

        return $major.str_pad($minor, 2, '0', STR_PAD_LEFT);
    }

    private function threePart(string $version): string
    {
        return implode('.', array_pad(explode('.', $version), 3, '0'));
    }
}
