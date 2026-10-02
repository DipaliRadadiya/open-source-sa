<?php

namespace App\Services\Server\WebServers;

use App\Services\Server\Certificates\CertificateFiles;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;

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
 * before the reload and put back as it was when the test fails — which is
 * what happens on a server that already has a default of its own (a server
 * moved from v7 has one), and that default is then left to serve.
 */
class CatchAllSite
{
    public function __construct(
        private WebServerManager $webServers,
        private ManagedFile $files,
        private ServerOps $serverOps,
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

        $previous = $this->files->get($config['path'], $context);
        $linked = $config['enabled'] === null
            || $this->serverOps->run(['test', '-L', $config['enabled']], $context)->ok;

        if ($previous->ok && $previous->output() === $config['contents'] && $linked) {
            return ['status' => 'current', 'reference' => null];
        }

        $written = $this->files->put($config['path'], $config['contents'], $context);

        if ($written->ok && $config['enabled'] !== null) {
            $written = $this->files->symlink($config['path'], $config['enabled'], $context);
        }

        $test = $written->ok ? $driver->test() : $written;

        if ($test->failed()) {
            // Back exactly as it was: the previous file if there was one,
            // nothing at all if there was not.
            if ($previous->ok) {
                $this->files->put($config['path'], $previous->output(), $context);

                if (! $linked) {
                    $this->files->delete($config['enabled'], $context);
                }
            } else {
                if ($config['enabled'] !== null) {
                    $this->files->delete($config['enabled'], $context);
                }
                $this->files->delete($config['path'], $context);
            }

            return ['status' => 'failed', 'reference' => $test->reference];
        }

        $driver->reload();

        return ['status' => 'updated', 'reference' => null];
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
