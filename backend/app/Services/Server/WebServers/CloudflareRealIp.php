<?php

namespace App\Services\Server\WebServers;

/**
 * The visitor's own address for a site behind Cloudflare.
 *
 * Behind Cloudflare every request reaches the server from one of Cloudflare's
 * addresses. Without this the access logs, the bot report, the 8G log and
 * fail2ban all see Cloudflare — and a fail2ban ban lands on an address that
 * carries many sites' real visitors. v7 has it on every server (nginx
 * `conf.d/remoteip.conf`, Apache `conf-enabled/remoteip.conf`, OpenLiteSpeed
 * `useIpInProxyHeader`); this is the same, with one change on OpenLiteSpeed:
 * v7 trusts the header from anyone, which lets any visitor choose the address
 * the server logs and bans. Here only Cloudflare's own ranges are trusted, on
 * every web server.
 *
 * Run by `sites:resync` (install, every update, the manual deploy runbook).
 * Written where v7 writes it, under v7's name; on a server that comes from v7
 * that file is v7's and is left as it is, as is a server whose owner set the
 * header anywhere else.
 */
class CloudflareRealIp
{
    /** Where the panel wrote these before it used v7's names (2026-11-11). */
    private const LEGACY_NGINX = '/etc/nginx/conf.d/panel-real-ip.conf';

    private const LEGACY_APACHE = '/etc/apache2/conf-available/panel-real-ip.conf';

    private const LEGACY_APACHE_LINK = '/etc/apache2/conf-enabled/panel-real-ip.conf';

    public function __construct(
        private WebServerManager $webServers,
        private SharedConfigFile $shared,
        private OlsSharedConfig $olsConfig,
        private OlsColdRestart $coldRestart,
    ) {}

    /**
     * `restart_needed`: written, but OpenLiteSpeed could not be restarted cold,
     * so it takes effect at its next full restart.
     *
     * @return array{status: 'updated'|'current'|'skipped'|'failed'|'restart_needed', reference: ?string}
     */
    public function ensure(): array
    {
        $driver = $this->webServers->driver();
        $context = ['feature' => 'web_server', 'op' => 'real_ip', 'web_server' => $driver->name()];
        $ranges = $this->ranges();

        if ($ranges === []) {
            return ['status' => 'skipped', 'reference' => null];
        }

        if ($driver->name() === 'openlitespeed') {
            $result = $this->olsConfig->trustProxies($ranges);

            // A graceful restart keeps the old trusted list (OlsColdRestart).
            if ($result['status'] === 'updated' && ! $this->coldRestart->run()) {
                $driver->reload();

                return ['status' => 'restart_needed', 'reference' => null];
            }

            return $result;
        }

        if ($driver->name() === 'nginx') {
            $path = (string) config('server.web_server_drivers.nginx.real_ip');

            return $this->writeUnlessSetElsewhere($path, $this->nginx($ranges), '/^\s*real_ip_header\s/m',
                [(string) config('server.web_server_drivers.nginx.config_path')], [dirname($path)],
                [self::LEGACY_NGINX => null], [], $context);
        }

        if ($driver->name() === 'apache') {
            $path = (string) config('server.web_server_drivers.apache.real_ip');

            return $this->writeUnlessSetElsewhere($path, $this->apache($ranges), '/^\s*RemoteIPHeader\s/mi',
                [], [dirname($path)],
                [self::LEGACY_APACHE => null], [self::LEGACY_APACHE_LINK => null], $context);
        }

        return ['status' => 'skipped', 'reference' => null];
    }

    /**
     * Write `$path` — v7's own file name — unless that file is v7's or the
     * owner's ({@see SharedConfigFile::ownership()}), or another file already
     * sets the header (a second one is a duplicate the web server refuses).
     * The panel's earlier name for it goes in the same change.
     *
     * @param  array<int, string>  $files
     * @param  array<int, string>  $dirs
     * @param  array<string, null>  $legacyFiles
     * @param  array<string, null>  $legacyLinks
     * @param  array<string, mixed>  $context
     * @return array{status: 'updated'|'current'|'skipped'|'failed', reference: ?string}
     */
    private function writeUnlessSetElsewhere(string $path, string $contents, string $pattern, array $files, array $dirs, array $legacyFiles, array $legacyLinks, array $context): array
    {
        if ($this->shared->ownership($path, null, $context) === 'foreign') {
            return ['status' => 'skipped', 'reference' => null];
        }

        $except = array_merge([$path], array_keys($legacyFiles), array_keys($legacyLinks));

        if (preg_match($pattern, $this->shared->otherConfigs($files, $dirs, $except, $context)) === 1) {
            return ['status' => 'skipped', 'reference' => null];
        }

        return $this->shared->apply([$path => $contents] + $legacyFiles, $legacyLinks, $context);
    }

    /** @return array<int, string> */
    private function ranges(): array
    {
        return array_values(array_filter(array_map('strval', array_merge(
            (array) config('server.cloudflare_ranges', []),
            (array) config('server.cloudflare_ranges_v6', []),
        ))));
    }

    /** @param  array<int, string>  $ranges */
    private function nginx(array $ranges): string
    {
        $lines = [SharedConfigFile::MARKER.': the visitor\'s own address behind Cloudflare.', 'real_ip_header CF-Connecting-IP;'];

        foreach ($ranges as $range) {
            $lines[] = "set_real_ip_from {$range};";
        }

        return implode("\n", $lines)."\n";
    }

    /** @param  array<int, string>  $ranges */
    private function apache(array $ranges): string
    {
        // Inert until mod_remoteip is enabled (install.sh and the updater do),
        // so the file can never stop Apache from starting.
        $lines = [SharedConfigFile::MARKER.': the visitor\'s own address behind Cloudflare.', '<IfModule remoteip_module>', '    RemoteIPHeader CF-Connecting-IP'];

        foreach ($ranges as $range) {
            $lines[] = "    RemoteIPTrustedProxy {$range}";
        }

        $lines[] = '</IfModule>';

        return implode("\n", $lines)."\n";
    }
}
