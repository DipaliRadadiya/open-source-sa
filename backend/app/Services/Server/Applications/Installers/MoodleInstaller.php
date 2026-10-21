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
 * Moodle's command-line installers are the least accommodating of the set:
 * `install.php` asks about fourteen things in interactive mode, with the admin
 * password inside a retry loop, and `install_database.php` refuses to run
 * without `--adminpass` — it has no prompt at all. Neither is a way to get a
 * secret in without putting it on the command line, where `ps` shows it to
 * every user on the machine.
 *
 * So the install is split so that no secret of the user's is ever an argument:
 *
 *  1. `config.php` is written here, carrying the database credentials. Moodle
 *     reads it, so they never reach a command line.
 *  2. `install_database.php` runs with a **throwaway** admin password. It is
 *     random, it is never shown to anyone, and it stops being valid moments
 *     later — so its brief appearance in `ps` discloses nothing.
 *  3. `reset_password.php` sets the password the user actually chose. That one
 *     prompts, and a single prompt can be answered on stdin reliably.
 *
 * Moodle's own password policy is enforced at validation rather than waived
 * here: waiving it would let a weak password through, and letting the reset
 * fail would leave the account on a random password nobody knows.
 */
class MoodleInstaller extends AbstractPhpInstaller implements ApplicationUpdater
{
    use ResolvesUpdates;

    /**
     * What Moodle's installer demands, and the CLI default is 1000.
     *
     * Its environment check reports this one as "this test must pass", so it
     * is a hard stop rather than the advisory the database-version line beside
     * it turns out to be.
     */
    private const MIN_INPUT_VARS = 5000;

    public function siteType(): string
    {
        return 'moodle';
    }

    /**
     * PostgreSQL last, so MySQL remains the first available engine and every
     * existing server keeps making the Moodle it already made.
     *
     * Moodle names all three in `config-dist.php`, and unlike most of the
     * marketplace it distinguishes MySQL from MariaDB — see {@see dbType()}.
     * Naming PostgreSQL here also commits to dropping `dbcollation`, which is
     * a MySQL-only option; the config template does that.
     *
     * @return array<int, string>
     */
    public function acceptedEngines(): array
    {
        return ['mysql', 'mariadb', 'postgresql'];
    }

    /**
     * Moodle 5.0's floors, from the `<MOODLE version="5.0">` block of
     * `admin/environment.xml` on `MOODLE_500_STABLE` — the line
     * `server.installers.moodle.download_url` pins: MariaDB 10.11.0, MySQL
     * 8.4, PostgreSQL 14.
     *
     * 🔴 MySQL used to carry no entry, on the argument that its minimum was too
     * old for any server to fall below. 5.0 raised it to 8.4, and Ubuntu 24.04
     * ships 8.0: every Moodle on a stock 24.04 box was offered, created, and
     * then refused by Moodle's own installer ("version 8.4 is required and you
     * are running 8.0.46"). Found by the CF-06 create matrix, 2026-10-21.
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

        // Moodle keeps uploaded files, caches and session data here. Upstream
        // is emphatic that it belongs outside the web root; inside it, every
        // student submission is a URL.
        $dataDir = dirname($documentRoot).'/moodledata';
        $this->run('configure', ['mkdir', '-p', $dataDir], $application);
        $this->run('configure', [
            'chown', '-R', "{$application->systemUser->username}:{$application->systemUser->username}", $dataDir,
        ], $application);
        $this->run('configure', ['chmod', '0750', $dataDir], $application);

        $isPostgres = ($context['engine'] ?? '') === 'postgresql';

        $this->writeSecretFile($application, "{$documentRoot}/config.php", View::make('server.apps.moodle.config', [
            'dbType' => $this->dbType($context),
            // `dbcollation` is MySQL's; config-dist.php says in as many words
            // that it "should be removed for all other databases". Handing a
            // PostgreSQL connection `utf8mb4_unicode_ci` names a collation
            // that does not exist there.
            'collation' => $isPostgres ? null : 'utf8mb4_unicode_ci',
            // Empty unless the port differs from the one Moodle's driver
            // assumes, which is what every Moodle the panel has made carries.
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

        // The password here is deliberately disposable — see the class note.
        // `-d max_input_vars` on the interpreter, not in the site's php.ini:
        // Moodle's installer refuses to run below 5000 — "this test must
        // pass", unlike the database version note beside it, which is only a
        // warning — and the CLI default is 1000. Setting it here fixes the
        // install without editing a php.ini the user may later want to own.
        //
        // The site's own pool still needs it for Moodle to run in a browser;
        // this flag does nothing for that. See the note in install().
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

        // Now the real one, on stdin. reset_password.php asks for exactly one
        // thing when given a username, which is what makes this reliable
        // where feeding install.php's fourteen prompts would not be.
        $this->runAsSiteUser('set_password', $application, [
            ...$php, '-d', 'max_input_vars='.self::MIN_INPUT_VARS,
            'admin/cli/reset_password.php', '--username='.$adminUser,
        ], ($settings['admin_password'] ?? '')."\n", $documentRoot);

        // Moodle wants its cron every minute: without it, no mail, no
        // backups, no cleanup, and a notice on every admin page.
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
     * A password that satisfies Moodle's policy and belongs to nobody.
     *
     * It exists only to get past a required argument and is replaced before
     * the site is usable.
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
        // Moodle distinguishes MySQL from MariaDB where most applications
        // don't, and picks a different driver for each. `pgsql` is its
        // PostgreSQL driver, per config-dist.php's own list.
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
     * Moodle's line is the branch (5.0, 4.5), not the major: 5.0 → 5.1 moved
     * the code under `public/`. The newest release of the branch is its
     * highest release tag — not the branch's `version.php` and not the
     * `moodle-latest-500` package: measured live (2026-10-13), the branch
     * already said 5.0.11 while `latest` still served 5.0.10, so an update
     * "to 5.0.11" installed 5.0.10.
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
     * v7 ran only `admin/cli/upgrade.php`, which upgrades the database to the
     * code already on disk — with no new code downloaded it never changed a
     * version. `config.php` survives the copy: the package ships only
     * `config-dist.php`. moodledata lives beside the code, not in it.
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
