<?php

namespace App\Services\Server\WebServers;

use App\Services\Server\Certificates\CertificateFiles;

/**
 * What a visitor gets for a domain no site on this server claims (bug #55).
 *
 * Left to the web server, nginx and Apache handed it to the first site they
 * loaded — a customer's site, pages and certificate, under any name somebody
 * pointed at the box — and OpenLiteSpeed showed the panel's certificate. v7
 * has a neutral default; this is the panel's.
 *
 * Run by `sites:resync`, so new installs, updates and the manual deploy
 * runbook all get it. Idempotent: nothing is written or reloaded when it is
 * already in place. Never takes the web server down: the config is tested
 * before the reload and put back as it was when the test fails.
 *
 * Under v7's file names (Dipali, 2026-11-11). A server moved from v7 has its
 * own default in those files, and it is left to serve, as on v7.
 */
class CatchAllSite
{
    public function __construct(
        private WebServerManager $webServers,
        private SharedConfigFile $shared,
        private CertificateFiles $certificateFiles,
        private OlsSharedConfig $olsConfig,
    ) {}

    /**
     * @return array{status: 'updated'|'current'|'skipped'|'failed', reference: ?string}
     */
    public function ensure(): array
    {
        $driver = $this->webServers->driver();
        $context = ['feature' => 'web_server', 'op' => 'catch_all', 'web_server' => $driver->name()];

        $fallback = $this->certificateFiles->ensureFallback();

        if ($fallback->failed()) {
            return ['status' => 'failed', 'reference' => $fallback->reference];
        }

        if ($driver->name() === 'openlitespeed') {
            $result = $this->olsConfig->neutralListenerCertificate($this->panelHosts());

            if ($result['status'] === 'updated') {
                $driver->reload();
            }

            return $result;
        }

        $config = $driver->catchAllConfig();

        if ($config === null) {
            return ['status' => 'skipped', 'reference' => null];
        }

        // v7's names are files a server from v7 — or its owner — already has.
        // Written only over nothing, the package's untouched original or the
        // panel's own; anything else is the server's own default, and it stays
        // (with the panel's earlier copy, so unknown names are still answered).
        foreach (array_keys($config['files']) as $path) {
            if ($this->shared->ownership($path, $config['package'], $context) === 'foreign') {
                return ['status' => 'skipped', 'reference' => null];
            }
        }

        foreach (array_keys($config['links']) as $link) {
            if ($this->shared->occupied($link, $context)) {
                return ['status' => 'skipped', 'reference' => null];
            }
        }

        return $this->shared->apply(
            $config['files'] + array_fill_keys($config['legacy'], null),
            $config['links'],
            $context,
        );
    }

    /**
     * The names the panel itself answers on — the API and the UI.
     *
     * @return array<int, string>
     */
    private function panelHosts(): array
    {
        return array_values(array_unique(array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            parse_url((string) config('server.storage.panel_url'), PHP_URL_HOST),
        ])));
    }
}
