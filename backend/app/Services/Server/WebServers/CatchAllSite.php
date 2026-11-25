<?php

namespace App\Services\Server\WebServers;

use App\Services\Server\Certificates\CertificateFiles;

/**
 * What a visitor gets for a domain no site on this server claims.
 *
 * Without it, nginx and Apache serve the first site they loaded (a customer's pages and
 * certificate) and OpenLiteSpeed shows the panel's certificate.
 *
 * Run by `sites:resync`. Idempotent. Never takes the web server down: the config is tested
 * before the reload and put back as it was when the test fails.
 *
 * Uses v7's file names; a server moved from v7 keeps its own default in those files.
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

        // Written only over nothing, the package's untouched original or the panel's own;
        // anything else is the server's own default and stays.
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
