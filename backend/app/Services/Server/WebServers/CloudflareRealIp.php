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
 * A server that already sets this itself — every server moved from v7 — is
 * left as it is.
 */
class CloudflareRealIp
{
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

            // A second `real_ip_header` is a duplicate nginx refuses.
            $others = $this->shared->otherConfigs([(string) config('server.web_server_drivers.nginx.config_path')], [dirname($path)], [$path], $context);

            if (preg_match('/^\s*real_ip_header\s/m', $others) === 1) {
                return ['status' => 'skipped', 'reference' => null];
            }

            return $this->shared->publish($path, $this->nginx($ranges), null, $context);
        }

        if ($driver->name() === 'apache') {
            $path = (string) config('server.web_server_drivers.apache.real_ip');
            $enabledDir = (string) config('server.web_server_drivers.apache.catch_all_enabled_dir');
            $enabled = $enabledDir.'/'.basename($path);

            if (preg_match('/^\s*RemoteIPHeader\s/mi', $this->shared->otherConfigs([], [$enabledDir], [$enabled], $context)) === 1) {
                return ['status' => 'skipped', 'reference' => null];
            }

            return $this->shared->publish($path, $this->apache($ranges), $enabled, $context);
        }

        return ['status' => 'skipped', 'reference' => null];
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
        $lines = ['# Managed by the panel: the visitor\'s own address behind Cloudflare.', 'real_ip_header CF-Connecting-IP;'];

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
        $lines = ['# Managed by the panel: the visitor\'s own address behind Cloudflare.', '<IfModule remoteip_module>', '    RemoteIPHeader CF-Connecting-IP'];

        foreach ($ranges as $range) {
            $lines[] = "    RemoteIPTrustedProxy {$range}";
        }

        $lines[] = '</IfModule>';

        return implode("\n", $lines)."\n";
    }
}
