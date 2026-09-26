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
        $settings = $application->installSettings();

        $this->downloadAndExtract(
            $application,
            (string) config('server.installers.wordpress.download_url'),
            $documentRoot,
        );

        $this->writeSecretFile($application, "{$documentRoot}/wp-config.php", View::make('server.apps.wordpress.wp-config', [
            'database' => $context['database'],
            'username' => $context['db_user'],
            'password' => $context['db_password'],
            // `host:port` when the port is not the one WordPress assumes —
            // wpdb parses that shape out of DB_HOST itself.
            'host' => $this->hostWithPort($context),
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
            ...$this->phpCommand($application),
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
                    ...$this->phpCommand($application),
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
        $wp = [
            ...$this->phpCommand($application),
            (string) config('server.installers.wordpress.wp_cli', '/usr/local/bin/wp'),
        ];

        // Constants first. A staging site pins WP_HOME/WP_SITEURL in
        // wp-config.php so a copied database can never point it at
        // production — and a constant overrides the option. Left alone, the
        // pin kept the address it was created with (http://) through every
        // certificate, and once the database already held the new address
        // `option update` failed outright ("Could not update option"), so
        // every resync of that site failed. Following the pin keeps its
        // protection and lets the address change. A normal site defines
        // neither, and nothing is written.
        foreach (['WP_HOME', 'WP_SITEURL'] as $constant) {
            if ($this->definesConstant($application, $wp, $constant, $documentRoot)) {
                $this->runAsSiteUser('sync_url', $application, [
                    ...$wp,
                    'config', 'set', $constant, $url, '--type=constant',
                    '--path='.$documentRoot,
                ], null, $documentRoot);
            }
        }

        foreach (['home', 'siteurl'] as $option) {
            $this->runAsSiteUser('sync_url', $application, [
                ...$wp,
                'option', 'update', $option, $url,
                '--path='.$documentRoot,
                '--skip-plugins', '--skip-themes',
            ], null, $documentRoot);
        }
    }

    /**
     * Whether wp-config.php defines this constant. `wp config has` answers
     * with its exit status, so it is run without the installer's
     * throw-on-failure wrapper: "no" is an answer here, not an error.
     *
     * @param  array<int, string>  $wp
     */
    private function definesConstant(Application $application, array $wp, string $constant, string $documentRoot): bool
    {
        return $this->serverOps->run(
            [
                'runuser', '-u', $application->systemUser->username, '--',
                ...$wp,
                'config', 'has', $constant, '--type=constant',
                '--path='.$documentRoot,
            ],
            ['feature' => 'application', 'op' => 'installer.sync_url_check', 'application' => $application->id],
            timeout: $this->timeout(),
            cwd: $documentRoot,
        )->ok;
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
