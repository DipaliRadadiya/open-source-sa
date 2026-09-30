<?php

namespace App\Services\Server\WebServers;

use App\Models\Application;

class NginxDriver extends AbstractWebServerDriver
{
    private ?bool $http2Directive = null;

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
        return parent::viewData($application, $documentRoot) + ['http2On' => $this->supportsHttp2Directive()];
    }

    /**
     * Whether this nginx takes `http2 on;` (1.25.1+). On those, the older
     * `listen 443 ssl http2` still works but is deprecated: `nginx -t` on
     * Ubuntu 26.04 (nginx 1.28) printed 36 warnings for 18 sites, and the
     * Services screen's config test showed every one. The old form stays
     * wherever the new one would be a hard error — Ubuntu 24.04 ships 1.24 —
     * and whenever the version cannot be read, since it works everywhere.
     * Asked once per driver instance.
     */
    public function supportsHttp2Directive(): bool
    {
        if ($this->http2Directive !== null) {
            return $this->http2Directive;
        }

        $result = $this->serverOps->run(['nginx', '-v'], ['feature' => 'web_server', 'op' => 'nginx_version'], timeout: 15);
        $text = $result->output().$result->errorOutput();

        return $this->http2Directive = $result->ok
            && preg_match('~nginx/(\d+\.\d+\.\d+)~', $text, $m) === 1
            && version_compare($m[1], '1.25.1', '>=');
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
     * Matches the `access_log` / `error_log` lines in the nginx vhost
     * templates. If those move, this moves with them.
     *
     * In the application's own `logs/` directory rather than
     * `/var/log/nginx`, so a site's logs are all in one place whichever web
     * server the box runs, and its owner can read them without root.
     * {@see Application::logsPath()}
     *
     * @return array<string, string>
     */
    public function logPaths(Application $application): array
    {
        $dir = $application->logsPath();

        return [
            'access' => "{$dir}/access.log",
            'error' => "{$dir}/error.log",
        ];
    }
}
