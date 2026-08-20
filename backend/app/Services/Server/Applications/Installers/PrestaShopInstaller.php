<?php

namespace App\Services\Server\Applications\Installers;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * PrestaShop — e-commerce.
 *
 * Three things are unlike anything else here.
 *
 * **The version comes from PrestaShop's own update feed, not from GitHub.**
 * Their 9.x tags publish no downloadable package at all, and their feed still
 * names the 8 branch as current stable — so following the feed installs what
 * upstream considers current, and keeps doing so when that changes. Reading
 * "latest release" off GitHub would have produced a card that 404s.
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
        $settings = $application->settings ?? [];

        $this->downloadAndExtract($application, null, $documentRoot);

        // The second unzip. Without it the web root holds an archive.
        $this->run('extract', ['unzip', '-q', '-o', "{$documentRoot}/prestashop.zip", '-d', $documentRoot], $application);
        $this->run('extract', ['rm', '-f', "{$documentRoot}/prestashop.zip"], $application);
        $this->run('extract', [
            'chown', '-R', "{$application->systemUser->username}:{$application->systemUser->username}", $documentRoot,
        ], $application);

        $this->runAsSiteUser('install_app', $application, [
            $this->phpBinary($application), 'install/index_cli.php',
            '--domain='.$application->domain,
            '--base_uri=/',
            '--db_server='.($context['db_host'] ?? '127.0.0.1'),
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
            // Their licence must be accepted for the install to proceed.
            '--license=1',
            // Never drop tables: the database is ours and freshly made, and a
            // retry must not be able to wipe a shop that already installed.
            '--db_clear=0',
        ], null, $documentRoot);

        // The installer directory is a working install wizard left in a public
        // web root; upstream requires its removal before the shop is usable.
        $this->run('harden', ['rm', '-rf', "{$documentRoot}/install"], $application);
    }

    /**
     * The current stable package, from PrestaShop's own channel feed.
     *
     * @throws ProvisioningFailedException
     */
    protected function downloadUrl(): string
    {
        $configured = (string) config('server.installers.prestashop.download_url', '');

        if ($configured !== '') {
            return $configured;
        }

        $response = Http::timeout(15)->get((string) config('server.installers.prestashop.channel_feed'));

        // Branches are listed oldest first, so the last stable one is current
        // — but only among branches that are PrestaShop itself. The feed also
        // carries the autoupgrade module, whose branch is listed last, and
        // taking the final link downloaded that instead: a module archive with
        // no `prestashop.zip` inside it, so the second unzip failed with
        // "cannot find or open .../prestashop.zip" on a site whose files had
        // already been written.
        //
        // Matched on the release filename rather than by excluding the module
        // by name. A deny-list is wrong the day they add a second one; this
        // only ever accepts something that looks like the shop.
        $url = null;
        if ($response->successful() && preg_match_all(
            '/<branch\s[^>]*>.*?<link>\s*([^<]+?)\s*<\/link>/s',
            $response->body(),
            $matches,
        )) {
            $url = collect($matches[1])
                ->filter(fn (string $link) => str_starts_with($link, 'https://'))
                ->filter(fn (string $link) => preg_match('#/prestashop_[\d.]+\.zip$#i', $link) === 1)
                ->last();
        }

        if (! is_string($url)) {
            // Their feed being unreachable must not turn into downloading
            // whatever else answers and unpacking it into a live web root.
            throw new ProvisioningFailedException('download', (string) Str::uuid());
        }

        return $url;
    }
}
