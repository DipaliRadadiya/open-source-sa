<?php

namespace App\Services\Server\Applications\Installers;

use App\Models\Application;
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
class MoodleInstaller extends AbstractPhpInstaller
{
    public function siteType(): string
    {
        return 'moodle';
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

        // Moodle keeps uploaded files, caches and session data here. Upstream
        // is emphatic that it belongs outside the web root; inside it, every
        // student submission is a URL.
        $dataDir = dirname($documentRoot).'/moodledata';
        $this->run('configure', ['mkdir', '-p', $dataDir], $application);
        $this->run('configure', [
            'chown', '-R', "{$application->systemUser->username}:{$application->systemUser->username}", $dataDir,
        ], $application);
        $this->run('configure', ['chmod', '0750', $dataDir], $application);

        $this->writeSecretFile($application, "{$documentRoot}/config.php", View::make('server.apps.moodle.config', [
            'dbType' => $this->dbType($context),
            'host' => $context['db_host'] ?? '127.0.0.1',
            'database' => $context['database'],
            'username' => $context['db_user'],
            'password' => $context['db_password'],
            'prefix' => $settings['table_prefix'] ?? 'mdl_',
            'wwwroot' => 'https://'.$application->domain,
            'dataroot' => $dataDir,
        ])->render());
        $steps[] = 'configure';

        $php = $this->phpBinary($application);
        $adminUser = (string) ($settings['admin_user'] ?? 'admin');

        // The password here is deliberately disposable — see the class note.
        $this->runAsSiteUser('install_app', $application, [
            $php, 'admin/cli/install_database.php',
            '--agree-license',
            '--adminuser='.$adminUser,
            '--adminemail='.($settings['admin_email'] ?? ''),
            '--fullname='.($settings['site_name'] ?? $application->name),
            '--shortname='.($settings['short_name'] ?? Str::limit(Str::slug((string) $application->name), 20, '')),
            '--adminpass='.$this->throwawayPassword(),
        ], null, $documentRoot);
        $steps[] = 'install_app';

        // Now the real one, on stdin. reset_password.php asks for exactly one
        // thing when given a username, which is what makes this reliable
        // where feeding install.php's fourteen prompts would not be.
        $this->runAsSiteUser('set_password', $application, [
            $php, 'admin/cli/reset_password.php', '--username='.$adminUser,
        ], ($settings['admin_password'] ?? '')."\n", $documentRoot);
        $steps[] = 'set_password';

        return $steps;
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
        // Moodle distinguishes the two where most applications don't, and
        // picks a different driver for each.
        return ($context['engine'] ?? '') === 'mariadb' ? 'mariadb' : 'mysqli';
    }
}
