<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * WordPress — the reference marketplace installer.
 *
 * Downloads core, writes wp-config.php with the database credentials and a
 * fresh set of salts, then runs `wp core install` as the site user to create
 * the admin account.
 */
class WordPressInstaller extends AbstractPhpInstaller
{
    public function siteType(): string
    {
        return 'wordpress';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $settings = $application->settings ?? [];

        $this->downloadAndExtract(
            $application,
            (string) config('server.installers.wordpress.download_url'),
            $documentRoot,
        );

        $this->writeSecretFile($application, "{$documentRoot}/wp-config.php", View::make('server.apps.wordpress.wp-config', [
            'database' => $context['database'],
            'username' => $context['db_user'],
            'password' => $context['db_password'],
            'host' => $context['db_host'] ?? '127.0.0.1',
            'prefix' => $settings['table_prefix'] ?? 'wp_',
            'salts' => $this->salts(),
        ])->render());

        $this->ensureWpCli($application);

        // The admin password goes in on stdin via --prompt, never as an
        // argument — `ps` is readable by every user on the machine.
        // Run the phar through the site's own interpreter rather than letting
        // its `#!/usr/bin/env php` shebang pick one. Two reasons: on
        // OpenLiteSpeed there may be no system `php` at all — LSPHP lives in
        // the lsws tree — and even on nginx the shebang finds whatever
        // /usr/bin/php happens to be, which need not be the version this site
        // was given.
        // Build the wp core install command. Arguments that are present only when
        // the user supplied a value are added as separate array items so array_filter
        // can exclude the nulls cleanly.
        $installCmd = array_filter([
            $this->phpBinary($application),
            (string) config('server.installers.wordpress.wp_cli', '/usr/local/bin/wp'),
            'core', 'install',
            '--path='.$documentRoot,
            '--url='.$application->url(),
            '--title='.($settings['site_title'] ?? $application->name),
            '--admin_user='.($settings['admin_user'] ?? 'admin'),
            '--admin_email='.($settings['admin_email'] ?? ''),
            '--skip-email',
            '--prompt=admin_password',
            // --locale is available on wp core install and sets the site language.
            // Omitting it defaults to en_US, which is already the form default.
            filled($settings['site_language'] ?? null)
                ? '--locale='.$settings['site_language']
                : null,
        ]);

        $this->runAsSiteUser('install_app', $application, $installCmd, ($settings['admin_password'] ?? '')."\n");

        // Timezone must be set after wp core install — it is not a flag on that
        // command. wp option update validates the value against PHP timezone
        // strings; a bad value is silently ignored by WordPress, so this step
        // is best-effort and never throws.
        if (filled($settings['timezone'] ?? null)) {
            try {
                $this->runAsSiteUser('set_timezone', $application, [
                    $this->phpBinary($application),
                    (string) config('server.installers.wordpress.wp_cli', '/usr/local/bin/wp'),
                    'option', 'update', 'timezone_string',
                    $settings['timezone'],
                    '--path='.$documentRoot,
                ]);
            } catch (ProvisioningFailedException $e) {
                // A site with a wrong timezone string still works. Log and continue.
                report($e);
            }
        }
    }

    public function syncUrl(Application $application, string $url): void
    {
        $documentRoot = $application->documentRoot();

        foreach (['home', 'siteurl'] as $option) {
            $this->runAsSiteUser('sync_url', $application, [
                $this->phpBinary($application),
                (string) config('server.installers.wordpress.wp_cli', '/usr/local/bin/wp'),
                'option', 'update', $option, $url,
                '--path='.$documentRoot,
                '--skip-plugins', '--skip-themes',
            ], null, $documentRoot);
        }
    }

    /**
     * wp-cli is not part of a base system, so fetch it on first use. Skipped
     * when it is already present, which is the normal case after one install.
     */
    private function ensureWpCli(Application $application): void
    {
        $path = (string) config('server.installers.wordpress.wp_cli', '/usr/local/bin/wp');

        if ($this->serverOps->run(['test', '-x', $path], ['feature' => 'application', 'op' => 'installer.wp_cli_check'])->ok) {
            return;
        }

        $this->run('install_cli', [
            'curl', '--fail', '--location', '--silent', '--show-error',
            '--proto', '=https', '--proto-redir', '=https',
            '--output', $path,
            (string) config('server.installers.wordpress.wp_cli_url'),
        ], $application);

        $this->run('install_cli', ['chmod', '0755', $path], $application);
    }

    /**
     * A fresh set of authentication keys per install.
     *
     * Fetched from WordPress's salt service, with locally generated randomness
     * as the fallback. Reusing salts across installs would let a leak from one
     * site forge sessions on another, so a static set is never acceptable —
     * better a locally random one than a shared one.
     *
     * @return array<string, string>
     */
    private function salts(): array
    {
        $keys = [
            'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
            'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
        ];

        try {
            $response = Http::timeout(10)->get((string) config('server.installers.wordpress.salt_url'));

            if ($response->successful()) {
                $parsed = [];

                foreach ($keys as $key) {
                    if (preg_match("/'{$key}',\\s*'(.*)'\\s*\\);/U", $response->body(), $m) === 1) {
                        $parsed[$key] = $m[1];
                    }
                }

                if (count($parsed) === count($keys)) {
                    return $parsed;
                }
            }
        } catch (\Throwable) {
            // Fall through to local randomness.
        }

        return collect($keys)->mapWithKeys(fn (string $key) => [$key => Str::random(64)])->all();
    }
}
