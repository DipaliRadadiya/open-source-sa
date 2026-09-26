<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Rules\SupportedPhpVersion;
use App\Services\Applications\Types\PrestaShopSiteType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * PrestaShop — e-commerce.
 *
 * Three things are unlike anything else here.
 *
 * **The release comes from PrestaShop's distribution API, chosen by PHP.**
 * Not GitHub — their 9.x tags publish no package at all — and no longer the
 * `channel.xml` feed, which on 2026-09-26 still named 8.2.1 as current stable
 * with 9.1.5 out since August. 8.x tops out at PHP 8.1, and LiteSpeed ships no
 * lsphp81 for Ubuntu 26.04, so following the feed made PrestaShop impossible
 * on every such server. {@see self::release()}
 *
 * **The download is a zip inside a zip.** The published archive contains a
 * single `prestashop.zip`; unpacking once leaves an archive in the web root
 * rather than a shop.
 *
 * **Its installer takes both passwords as arguments and offers nothing else.**
 * `install/index_cli.php` reads `$argv` and never touches stdin — there is no
 * prompt to answer. Every other installer here keeps secrets off the command
 * line; this one cannot, so it is the deliberate exception rather than an
 * oversight, and the API reference says so plainly.
 */
class PrestaShopInstaller extends AbstractPhpInstaller
{
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
            // PrestaShop's CLI has no --db_port; its own docs say to put a
            // non-3306 port here as `host:port`.
            '--db_server='.$this->hostWithPort($context),
            '--db_name='.$context['database'],
            '--db_user='.$context['db_user'],
            // See the class note: PrestaShop's installer has no prompt, so
            // these two are the only way in.
            '--db_password='.$context['db_password'],
            '--email='.($settings['admin_email'] ?? ''),
            '--password='.($settings['admin_password'] ?? ''),
            '--firstname='.($settings['admin_first_name'] ?? 'Admin'),
            '--lastname='.($settings['admin_last_name'] ?? 'User'),
            '--shop_name='.($settings['shop_name'] ?? $application->name),
            '--country='.($settings['country'] ?? 'gb'),
            '--language='.($settings['language'] ?? 'en'),
            '--timezone='.($settings['timezone'] ?? 'UTC'),
            '--prefix='.($settings['table_prefix'] ?? 'ps_'),
            // No `--license`. It reads as "accept the licence" and is the
            // opposite: PrestaShop's own datas.php defines it as
            // `'show_license' => ['name' => 'license', 'default' => 0,
            // 'help' => 'show PrestaShop license']`. Passing 1 told the
            // installer to print the licence and stop, so every install exited
            // 0 in a third of a second having created nothing, and the shop
            // then answered `"install" directory is missing`.
            //
            // The default is correct, so the option is simply absent.
            // Never drop tables: the database is ours and freshly made, and a
            // retry must not be able to wipe a shop that already installed.
            '--db_clear=0',
        ], null, $documentRoot);

        // Exit 0 is not evidence that anything happened. `index_cli.php` has
        // returned success in half a second having written no configuration at
        // all, leaving a shop that answers every request with
        // `"install" directory is missing` — because the step below had by then
        // removed the wizard that could have finished the job.
        //
        // So the config it must write is checked before anything is destroyed,
        // the same shape as the restore flow's VerifyDownload: the last gate
        // before the irreversible step. Failing here leaves `install/` in place,
        // which is the difference between a site somebody can rescue in a
        // browser and one that can only be deleted.
        $this->assertInstalled($application, $documentRoot);

        $this->recordRelease($application, $release);

        // The installer directory is a working install wizard left in a public
        // web root; upstream requires its removal before the shop is usable.
        $this->run('harden', ['rm', '-rf', "{$documentRoot}/install"], $application);
    }

    /**
     * Did the CLI installer actually install anything?
     *
     * PrestaShop writes its database credentials into `app/config/parameters.php`
     * as the last thing it does, so its presence is the one cheap signal that
     * the run got to the end. Older branches used `config/settings.inc.php`;
     * both are accepted, since which branch is installed depends on the
     * site's PHP.
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
     * From `api.prestashop-project.org/prestashop`, the list PrestaShop's own
     * Docker images build from: every release with its stability, its PHP
     * range and its package URL. Chosen per site rather than "latest", because
     * the two branches do not overlap — 9.1 runs on 8.1 – 8.5 and 8.2 on
     * 7.2.5 – 8.1 — so an 8.4 shop gets 9.1 and a 7.4 shop gets 8.2, and
     * neither is handed a release that dies on its interpreter.
     *
     * Ranges are compared as major.minor ({@see SupportedPhpVersion}): the
     * site holds `7.2`, and `7.2.5` as a floor would otherwise exclude it.
     *
     * The package layout is the same on both branches — a zip holding
     * `prestashop.zip`, unpacked below — measured on 8.2.8 and 9.1.5.
     *
     * @return array{zip_download_url: string, version?: string, php_min_version?: string, php_max_version?: string}
     *
     * @throws ProvisioningFailedException
     */
    private function release(Application $application): array
    {
        $configured = (string) config('server.installers.prestashop.download_url', '');

        if ($configured !== '') {
            // Pinned by the operator: nothing is known about its PHP range, so
            // none is recorded and the shop keeps PrestaShop 8's.
            return ['zip_download_url' => $configured];
        }

        $php = $this->phpVersion($application);
        $response = Http::timeout(15)->get((string) config('server.installers.prestashop.releases_api'));
        $releases = $response->successful() ? $response->json() : null;

        $release = collect(is_array($releases) ? $releases : [])
            ->filter(fn (mixed $release) => is_array($release)
                && ($release['stability'] ?? null) === 'stable'
                && is_string($release['version'] ?? null)
                && is_string($release['zip_download_url'] ?? null)
                // The download step refuses anything else, including on a
                // redirect; choosing an http entry would only fail later.
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
            // Unreachable, or nothing runs on this PHP. Either way nothing is
            // downloaded — never whatever else answers, unpacked into a live
            // web root.
            throw new ProvisioningFailedException('download', (string) Str::uuid());
        }

        return $release;
    }

    /**
     * Keep the installed release's PHP range on the shop.
     *
     * The type's range is the union of every release the installer might
     * choose; a shop has exactly one, and the PHP screen must hold it to that
     * one. {@see PrestaShopSiteType::supportedPhpRangeFor()}
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
     * `install/index_cli.php` is given `--domain=` at install time and writes
     * it to `shop_url.domain`; nothing has ever updated it since. So a shop
     * issued a certificate served its pages over https while every image,
     * stylesheet and generated link still pointed at http — the browser sees
     * the mix and drops the padlock. The certificate was never the problem.
     *
     * ## Why this one writes SQL
     *
     * Every other syncUrl here edits a config file or calls the application's
     * own CLI. PrestaShop has neither for this: the value lives in
     * `shop_url` and `configuration`, and its console ships no command to
     * change them. Four rows, by name, with bound parameters.
     *
     * `domain_ssl` matters as much as `domain` — PrestaShop reads that one
     * when building an https link, and a shop with the two disagreeing
     * generates links to whichever address is in the wrong column.
     *
     * ## Where the credentials come from
     *
     * The shop's own `app/config/parameters.php`. The panel does not store
     * database passwords — it generates one at install and forgets it — so
     * the only account that can still reach this database is the shop, and
     * the file is read by a process running as the site user, never by the
     * panel. The prefix comes from there too rather than from the panel's
     * saved setting: the shop is the authority on its own table names, and a
     * setting edited afterwards would send this at tables that do not exist.
     *
     * ## Why stdin
     *
     * The domain is user input. Interpolating it into a `php -r` program
     * would be building code out of it — so the program is fixed and its
     * three values arrive as JSON on stdin, the same reason every query below
     * binds rather than concatenates.
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

        // The compiled container and Smarty templates hold the old address.
        // Best-effort and deliberately last: the rows are already correct, and
        // failing here would roll a certificate back over a cache directory
        // that the next request rebuilds anyway.
        try {
            $this->runAsSiteUser('sync_url', $application, [
                'sh', '-c', 'rm -rf '.escapeshellarg($documentRoot.'/var/cache').'/*',
            ], null, $documentRoot);
        } catch (ProvisioningFailedException $e) {
            report($e);
        }
    }

    /**
     * The program run as the site user. Fixed text — every value it works on
     * arrives on stdin. {@see self::syncUrl()}
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
}
