<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\WpCli\WpCli;
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
            // `host:port` when the port is non-default; wpdb parses it out of DB_HOST.
            'host' => $this->hostWithPort($context),
            'prefix' => $settings['table_prefix'] ?? 'wp_',
            'salts' => $this->salts(),
        ])->render());

        $this->ensureWpCli($application);

        // The admin password goes in on stdin via --prompt, never as an argument: `ps` is
        // readable by every user on the machine.
        // The phar runs through the site's own interpreter, not its `#!/usr/bin/env php`
        // shebang: OpenLiteSpeed may have no system `php`, and /usr/bin/php may be another version.
        // Optional arguments are null when unset, so array_filter drops them.
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
            // Omitted, the site language defaults to en_US.
            filled($settings['site_language'] ?? null)
                ? '--locale='.$settings['site_language']
                : null,
        ]);

        $this->runAsSiteUser('install_app', $application, $installCmd, ($settings['admin_password'] ?? '')."\n");

        // Timezone is not a flag of `wp core install`, so it is set afterwards. A bad value is
        // silently ignored by WordPress, so this step is best-effort and never throws.
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
                // A wrong timezone string does not break the site: report and continue.
                report($e);
            }
        }

        // `--locale` above only saves WPLANG without downloading the language files, so fetch and
        // activate them here. Best-effort like the timezone: an offline server keeps English.
        $locale = (string) ($settings['site_language'] ?? '');

        if ($locale !== '' && $locale !== 'en_US') {
            try {
                $this->runAsSiteUser('install_language', $application, [
                    ...$this->phpCommand($application),
                    (string) config('server.installers.wordpress.wp_cli', '/usr/local/bin/wp'),
                    'language', 'core', 'install', $locale, '--activate',
                    '--path='.$documentRoot,
                ]);
            } catch (ProvisioningFailedException $e) {
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

        // Read before anything changes: content follows only a real change of scheme.
        $previous = $this->currentHome($application, $wp, $documentRoot);

        // Constants first. A staging site pins WP_HOME/WP_SITEURL in wp-config.php so a copied
        // database can never point it at production, and a constant overrides the option. Update
        // the pin too: otherwise it keeps the old address and `option update` fails once the
        // database holds the new one. A normal site defines neither; nothing is written.
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

        $this->followSchemeInContent($application, $wp, $documentRoot, $previous, $url);
    }

    /**
     * The site's own links in its content, moved to the new scheme with it.
     *
     * Otherwise posts keep `http://` links to the site on a page now served over https, which
     * browsers block as mixed content. A failed certificate calls this back with the old
     * address, which turns the links back.
     *
     * Only on a real scheme change for the same host: this runs on every routine resync and a
     * full-table search-replace would be waste. The host is matched up to its end, so
     * `http://example.com.au` is not mistaken for this site. Best effort: a failure here must
     * not fail the certificate.
     *
     * @param  array<int, string>  $wp
     */
    private function followSchemeInContent(Application $application, array $wp, string $documentRoot, ?string $previous, string $url): void
    {
        $from = parse_url((string) $previous);
        $to = parse_url($url);

        if (! isset($from['scheme'], $from['host'], $to['scheme'], $to['host'])
            || $from['host'] !== $to['host'] || $from['scheme'] === $to['scheme']) {
            return;
        }

        $host = preg_quote($to['host'], '#');
        $end = '(?![A-Za-z0-9-]|\\.[A-Za-z0-9])';

        foreach (['://', ':\\/\\/'] as $separator) {
            $this->serverOps->run(
                [
                    'runuser', '-u', $application->systemUser->username, '--',
                    ...$wp,
                    'search-replace',
                    $from['scheme'].preg_quote($separator, '#').$host.$end,
                    $to['scheme'].$separator.$to['host'],
                    '--regex', '--regex-delimiter=#',
                    '--path='.$documentRoot,
                    '--all-tables', '--precise', '--recurse-objects',
                    '--skip-columns=guid',
                    '--skip-plugins', '--skip-themes',
                ],
                ['feature' => 'application', 'op' => 'installer.sync_url_content', 'application' => $application->id],
                timeout: $this->timeout(),
                cwd: $documentRoot,
            );
        }
    }

    /**
     * `home` as WordPress answers it (a pinned WP_HOME included), or null.
     *
     * @param  array<int, string>  $wp
     */
    private function currentHome(Application $application, array $wp, string $documentRoot): ?string
    {
        $result = $this->serverOps->run(
            [
                'runuser', '-u', $application->systemUser->username, '--',
                ...$wp,
                'option', 'get', 'home',
                '--path='.$documentRoot,
                '--skip-plugins', '--skip-themes',
            ],
            ['feature' => 'application', 'op' => 'installer.sync_url_check', 'application' => $application->id],
            timeout: $this->timeout(),
            cwd: $documentRoot,
        );

        $home = trim($result->output());

        return $result->ok && $home !== '' ? $home : null;
    }

    /**
     * Whether wp-config.php defines this constant. `wp config has` answers with its exit status,
     * so "no" is an answer here, not an error.
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
            // Exit 1 = "not defined", not a failure to log.
            expectedExitCodes: [1],
        )->ok;
    }

    /**
     * wp-cli is not part of a base system: fetched on first use, skipped when present.
     */
    private function ensureWpCli(Application $application): void
    {
        $wpCli = app(WpCli::class);

        if ($wpCli->installed()) {
            return;
        }

        foreach ($wpCli->installCommands() as $command) {
            $this->run('install_cli', $command, $application);
        }
    }

    /**
     * A fresh set of authentication keys per install.
     *
     * Fetched from WordPress's salt service, with local randomness as the fallback. Never reuse
     * a static set: a leak from one site would let sessions be forged on another.
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
