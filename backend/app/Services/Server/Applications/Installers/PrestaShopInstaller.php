<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Rules\SupportedPhpVersion;
use App\Services\Applications\Types\PrestaShopSiteType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * PrestaShop — e-commerce.
 *
 * Three things are unlike anything else here.
 *
 * **The release comes from PrestaShop's distribution API, chosen by PHP.** Not GitHub (9.x tags
 * publish no package) and not the `channel.xml` feed (stale; 8.x tops out at PHP 8.1, which
 * LiteSpeed does not ship for Ubuntu 26.04). {@see self::release()}
 *
 * **The download is a zip inside a zip:** unpacking once leaves a `prestashop.zip` in the web root.
 *
 * **Its installer takes both passwords as arguments and offers nothing else.**
 * `install/index_cli.php` reads `$argv`, never stdin. Every other installer keeps secrets off
 * the command line; this one cannot, so it is the deliberate exception.
 */
class PrestaShopInstaller extends AbstractPhpInstaller
{
    /**
     * Characters a shop name cannot carry through PrestaShop's CLI installer: `< > = { }` fail
     * its isGenericName check, and its argument parser drops a value containing `=`, `|` or a backtick.
     */
    public const SHOP_NAME_FORBIDDEN = '/[<>={}|`]/';

    public function siteType(): string
    {
        return 'prestashop';
    }

    protected function archiveFormat(): string
    {
        return 'zip';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): void
    {
        $settings = $application->installSettings();

        $release = $this->release($application);

        $this->downloadAndExtract($application, $release['zip_download_url'], $documentRoot);

        // The second unzip. Without it the web root holds an archive.
        $this->run('extract', ['unzip', '-q', '-o', "{$documentRoot}/prestashop.zip", '-d', $documentRoot], $application);
        $this->run('extract', ['rm', '-f', "{$documentRoot}/prestashop.zip"], $application);
        $this->run('extract', [
            'chown', '-R', "{$application->systemUser->username}:{$application->systemUser->username}", $documentRoot,
        ], $application);

        $this->runAsSiteUser('install_app', $application, [
            ...$this->phpCommand($application), 'install/index_cli.php',
            '--domain='.$application->domain,
            '--base_uri=/',
            // PrestaShop's CLI has no --db_port: a non-3306 port goes here as `host:port`.
            '--db_server='.$this->hostWithPort($context),
            '--db_name='.$context['database'],
            '--db_user='.$context['db_user'],
            // See the class note: no prompt, so these two are the only way in.
            '--db_password='.$context['db_password'],
            '--email='.($settings['admin_email'] ?? ''),
            '--password='.($settings['admin_password'] ?? ''),
            '--firstname='.($settings['admin_first_name'] ?? 'Admin'),
            '--lastname='.($settings['admin_last_name'] ?? 'User'),
            // `--name`, not `--shop_name`: datas.php ignores options it does not know.
            '--name='.$this->shopName($settings['shop_name'] ?? $application->name),
            '--country='.($settings['country'] ?? 'gb'),
            '--language='.($settings['language'] ?? 'en'),
            '--timezone='.($settings['timezone'] ?? 'UTC'),
            '--prefix='.($settings['table_prefix'] ?? 'ps_'),
            // No `--license`: despite the name it means "show the licence and stop" (datas.php
            // `show_license`), so passing 1 exits 0 having installed nothing. The default is right.
            // Never drop tables: a retry must not wipe a shop that already installed.
            '--db_clear=0',
        ], null, $documentRoot);

        // Exit 0 is not evidence: `index_cli.php` can succeed having written no configuration. The
        // config is checked before anything is destroyed (the last gate before the irreversible
        // step); failing here leaves `install/` in place so the site can be rescued in a browser.
        $this->assertInstalled($application, $documentRoot);

        $this->recordRelease($application, $release);

        // The installer directory is a working install wizard left in a public
        // web root; upstream requires its removal before the shop is usable.
        $this->run('harden', ['rm', '-rf', "{$documentRoot}/install"], $application);
    }

    /**
     * Did the CLI installer actually install anything?
     *
     * PrestaShop writes `app/config/parameters.php` last, so its presence signals the run finished.
     * Older branches used `config/settings.inc.php`; both are accepted (the branch depends on the
     * site's PHP).
     *
     * @throws ProvisioningFailedException
     */
    private function assertInstalled(Application $application, string $documentRoot): void
    {
        foreach (['app/config/parameters.php', 'config/settings.inc.php'] as $candidate) {
            $found = $this->serverOps->run(
                ['test', '-f', "{$documentRoot}/{$candidate}"],
                ['feature' => 'application', 'op' => 'installer.verify_install', 'application' => $application->id],
                timeout: 15,
            );

            if ($found->ok) {
                return;
            }
        }

        throw new ProvisioningFailedException('install_app', (string) Str::uuid());
    }

    /**
     * The newest stable release whose PHP range holds this site's PHP.
     *
     * From `api.prestashop-project.org/prestashop` (stability, PHP range and package URL per
     * release). Chosen per site, not "latest": the branches do not overlap (9.1 runs on PHP
     * 8.1 – 8.5, 8.2 on 7.2.5 – 8.1), so an 8.4 shop gets 9.1 and a 7.4 shop gets 8.2.
     *
     * Ranges are compared as major.minor ({@see SupportedPhpVersion}): the site holds `7.2`, which
     * a `7.2.5` floor would exclude. Both branches package a zip holding `prestashop.zip`.
     *
     * @return array{zip_download_url: string, version?: string, php_min_version?: string, php_max_version?: string}
     *
     * @throws ProvisioningFailedException
     */
    private function release(Application $application): array
    {
        $configured = (string) config('server.installers.prestashop.download_url', '');

        if ($configured !== '') {
            // Pinned by the operator: its PHP range is unknown, so none is recorded.
            return ['zip_download_url' => $configured];
        }

        $php = $this->phpVersion($application);
        // A release host that does not answer is a reason, not a bare "download failed".
        try {
            $response = Http::timeout(15)->get((string) config('server.installers.prestashop.releases_api'));
        } catch (ConnectionException) {
            throw new ProvisioningFailedException('download', (string) Str::uuid(), 'download_unreachable');
        }
        $releases = $response->successful() ? $response->json() : null;

        $release = collect(is_array($releases) ? $releases : [])
            ->filter(fn (mixed $release) => is_array($release)
                && ($release['stability'] ?? null) === 'stable'
                && is_string($release['version'] ?? null)
                && is_string($release['zip_download_url'] ?? null)
                // The download step refuses anything but https, redirects included.
                && str_starts_with($release['zip_download_url'], 'https://')
                && is_string($release['php_min_version'] ?? null)
                && is_string($release['php_max_version'] ?? null)
                && SupportedPhpVersion::within(
                    $this->majorMinor($release['php_min_version']),
                    $this->majorMinor($release['php_max_version']),
                    $php,
                ))
            ->sort(fn (array $a, array $b) => version_compare($b['version'], $a['version']))
            ->first();

        if ($release === null) {
            // Unreachable, or nothing runs on this PHP: download nothing rather than unpack
            // whatever else answers into a live web root.
            throw new ProvisioningFailedException('download', (string) Str::uuid(), $response->successful() ? 'release_not_found' : 'download_unreachable');
        }

        return $release;
    }

    /**
     * Keep the installed release's PHP range on the shop.
     *
     * The type's range is the union of every release; a shop has one, and the PHP screen must hold
     * it to that. {@see PrestaShopSiteType::supportedPhpRangeFor()}
     *
     * @param  array<string, mixed>  $release
     */
    private function recordRelease(Application $application, array $release): void
    {
        if (! is_string($release['php_min_version'] ?? null) || ! is_string($release['php_max_version'] ?? null)) {
            return;
        }

        $application->forceFill(['settings' => [
            ...(array) $application->settings,
            'prestashop_version' => $release['version'] ?? null,
            'php_range' => [
                'min' => $this->majorMinor($release['php_min_version']),
                'max' => $this->majorMinor($release['php_max_version']),
            ],
        ]])->save();
    }

    private function majorMinor(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    /**
     * PrestaShop keeps its own copy of the shop address, in the database.
     *
     * `install/index_cli.php` writes `--domain=` to `shop_url.domain` once and nothing updates it,
     * so after a certificate the pages are https while assets and links stay http.
     *
     * ## Why this one writes SQL
     *
     * PrestaShop has no config file or console command for this: the value lives in `shop_url`
     * and `configuration`. Four rows, by name, with bound parameters. `domain_ssl` matters as much
     * as `domain` (it is read when building https links).
     *
     * ## Where the credentials come from
     *
     * The shop's own `app/config/parameters.php`, read by a process running as the site user, never
     * by the panel (which does not store database passwords). The prefix comes from there too: the
     * shop is the authority on its table names.
     *
     * ## Why stdin
     *
     * The domain is user input: the `php -r` program is fixed and its values arrive as JSON on stdin,
     * never interpolated into code.
     */
    public function syncUrl(Application $application, string $url): void
    {
        $documentRoot = $application->documentRoot();
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '') {
            throw new \RuntimeException('PrestaShop was given a URL with no host.');
        }

        $this->runAsSiteUser('sync_url', $application, [
            ...$this->phpCommand($application), '-r', $this->syncUrlProgram(),
        ], json_encode([
            'parameters' => $documentRoot.'/app/config/parameters.php',
            'domain' => $host,
            'ssl' => parse_url($url, PHP_URL_SCHEME) === 'https' ? 1 : 0,
        ], JSON_THROW_ON_ERROR), $documentRoot);

        // The compiled container and Smarty templates hold the old address. Best-effort and last:
        // failing here must not roll a certificate back over a cache the next request rebuilds.
        try {
            $this->runAsSiteUser('sync_url', $application, [
                'sh', '-c', 'rm -rf '.escapeshellarg($documentRoot.'/var/cache').'/*',
            ], null, $documentRoot);
        } catch (ProvisioningFailedException $e) {
            report($e);
        }
    }

    /**
     * The program run as the site user. Fixed text; every value arrives on stdin. {@see self::syncUrl()}
     */
    private function syncUrlProgram(): string
    {
        return <<<'PHP'
        $in = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($in) || !is_file($in['parameters'])) {
            fwrite(STDERR, "PrestaShop parameters.php was not found.\n");
            exit(1);
        }
        $config = require $in['parameters'];
        $p = $config['parameters'] ?? null;
        if (!is_array($p) || !isset($p['database_name'], $p['database_user'])) {
            fwrite(STDERR, "PrestaShop parameters.php holds no database settings.\n");
            exit(1);
        }
        // `database_host` carries an optional :port, as PrestaShop writes it.
        $host = (string) ($p['database_host'] ?? '127.0.0.1');
        $port = (string) ($p['database_port'] ?? '');
        if ($port === '' && substr_count($host, ':') === 1) {
            [$host, $port] = explode(':', $host);
        }
        $dsn = 'mysql:host='.$host.($port !== '' ? ';port='.$port : '').';dbname='.$p['database_name'];
        // Count rows matched, not rows changed. MySQL's default counts only
        // rows whose value moved, so re-applying the address a shop already
        // has — every sites:resync does — read as "nothing updated" and failed
        // a correct shop on every deploy. The new name where PHP has it: the
        // old constant is deprecated from 8.5, and a notice here lands in the
        // server-ops log as though something went wrong.
        $foundRows = defined('Pdo\\Mysql::ATTR_FOUND_ROWS')
            ? constant('Pdo\\Mysql::ATTR_FOUND_ROWS')
            : PDO::MYSQL_ATTR_FOUND_ROWS;
        try {
            $pdo = new PDO($dsn, $p['database_user'], (string) ($p['database_password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                $foundRows => true,
            ]);
        } catch (Throwable $e) {
            fwrite(STDERR, "PrestaShop database is unreachable: ".$e->getMessage()."\n");
            exit(1);
        }
        // Not bindable — an identifier, not a value — so it is constrained to
        // what PrestaShop's own installer accepts as a prefix instead.
        $prefix = (string) ($p['database_prefix'] ?? 'ps_');
        if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
            fwrite(STDERR, "PrestaShop table prefix is not a plain identifier.\n");
            exit(1);
        }
        try {
            // Both columns: PrestaShop reads domain_ssl when building an https
            // link, so leaving it behind swaps one wrong address for another.
            $shop = $pdo->prepare('UPDATE `'.$prefix.'shop_url` SET `domain` = ?, `domain_ssl` = ?');
            $shop->execute([$in['domain'], $in['domain']]);
            $conf = $pdo->prepare(
                'UPDATE `'.$prefix.'configuration` SET `value` = ? '
                ."WHERE `name` IN ('PS_SSL_ENABLED', 'PS_SSL_ENABLED_EVERYWHERE')"
            );
            $conf->execute([(string) $in['ssl']]);
        } catch (Throwable $e) {
            // A wrong prefix names tables that do not exist, and uncaught that
            // arrives as a fatal-error dump with a stack trace in the
            // server-ops log. Same failure, one line, still non-zero.
            fwrite(STDERR, "PrestaShop tables could not be updated: ".$e->getMessage()."\n");
            exit(1);
        }
        // A shop whose rows were not even found is a shop this did nothing
        // for, and silence would read as success to every caller above.
        if ($shop->rowCount() === 0 && $conf->rowCount() === 0) {
            fwrite(STDERR, "PrestaShop shop_url and configuration were not updated.\n");
            exit(1);
        }
        PHP;
    }

    /**
     * The form refuses these characters; the application name used as a fallback never went
     * through that rule.
     */
    private function shopName(string $name): string
    {
        $clean = trim((string) preg_replace(self::SHOP_NAME_FORBIDDEN, '', $name));

        return $clean !== '' ? $clean : 'Shop';
    }
}
