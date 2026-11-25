<?php

namespace App\Services\Server\WebServers;

use App\Models\Application;
use App\Services\Server\ServerOpsResult;

class NginxDriver extends AbstractWebServerDriver
{
    private ?bool $http2Directive = null;

    private ?bool $ipv6 = null;

    private ?string $version = null;

    public function name(): string
    {
        return 'nginx';
    }

    /**
     * The nginx templates choose their HTTP/2 syntax by the installed nginx.
     *
     * @return array<string, mixed>
     */
    protected function viewData(Application $application, string $documentRoot): array
    {
        return parent::viewData($application, $documentRoot) + [
            'http2On' => $this->supportsHttp2Directive(),
            'rejectHandshake' => $this->rejectsHandshake(),
            'ipv6' => $this->hasIpv6(),
        ];
    }

    /**
     * Whether the templates may listen on `[::]`. With `ipv6.disable=1` at boot there is no
     * AF_INET6, and a `[::]` listen fails `nginx -t` ("Address family not supported by protocol").
     * The kernel's IPv6 interface list exists exactly when IPv6 does; install.sh's have_ipv6 asks
     * the same file. Asked once per driver instance: it only changes with a reboot.
     */
    public function hasIpv6(): bool
    {
        return $this->ipv6 ??= file_exists((string) config('server.ipv6_probe_file', '/proc/net/if_inet6'));
    }

    /**
     * `ssl_reject_handshake` arrived in 1.19.4; before that it is an unknown directive and
     * `nginx -t` fails. False when the version cannot be read (the older form works everywhere).
     */
    public function rejectsHandshake(): bool
    {
        return $this->versionAtLeast('1.19.4');
    }

    /**
     * A site without a certificate on an nginx that cannot refuse the handshake presents the
     * panel's reserved pair, so the pair must be on disk before the config names it.
     */
    protected function ensureTlsFallback(Application $application): ServerOpsResult
    {
        if ($application->certificate?->servable() || $this->rejectsHandshake()) {
            return new ServerOpsResult(true, 'tls-fallback-not-required');
        }

        return $this->certificateFiles->ensureFallback();
    }

    /**
     * Whether this nginx takes `http2 on;` (1.25.1+). There the older `listen 443 ssl http2` is
     * deprecated and makes `nginx -t` warn for every site. The old form stays on older nginx
     * (where the new one is a hard error) and when the version cannot be read. Asked once per
     * driver instance.
     */
    public function supportsHttp2Directive(): bool
    {
        if ($this->http2Directive !== null) {
            return $this->http2Directive;
        }

        return $this->http2Directive = $this->versionAtLeast('1.25.1');
    }

    /** False when the version cannot be read: the older syntax works everywhere. */
    private function versionAtLeast(string $minimum): bool
    {
        $this->version ??= (function (): string {
            $result = $this->serverOps->run(['nginx', '-v'], ['feature' => 'web_server', 'op' => 'nginx_version'], timeout: 15);

            return $result->ok && preg_match('~nginx/(\d+\.\d+\.\d+)~', $result->output().$result->errorOutput(), $m) === 1
                ? $m[1]
                : '';
        })();

        return $this->version !== '' && version_compare($this->version, $minimum, '>=');
    }

    /**
     * The catch-all chooses the same way as a site ({@see rejectsHandshake()}).
     *
     * @return array<string, mixed>
     */
    protected function catchAllViewData(): array
    {
        return parent::catchAllViewData() + ['rejectHandshake' => $this->rejectsHandshake(), 'ipv6' => $this->hasIpv6(), 'http2On' => $this->supportsHttp2Directive()];
    }

    public function test(): ServerOpsResult
    {
        return app(NginxServerNameHash::class)->test(fn () => parent::test());
    }

    protected function testCommand(): array
    {
        return ['nginx', '-t'];
    }

    protected function reloadCommand(): array
    {
        return ['systemctl', 'reload', 'nginx'];
    }

    /**
     * Matches the `access_log` / `error_log` lines in the nginx vhost templates; if those move,
     * this moves with them.
     *
     * In the application's own `logs/` directory, not `/var/log/nginx`, so the owner can read
     * them without root. {@see Application::logsPath()}
     *
     * @return array<string, string>
     */
    public function logPaths(Application $application): array
    {
        $dir = $application->logsPath();

        return [
            'access' => "{$dir}/access.log",
            'error' => "{$dir}/error.log",
            // The HTTPS block's logs.
            'access_ssl' => "{$dir}/access-ssl.log",
            'error_ssl' => "{$dir}/error-ssl.log",
        ];
    }
}
