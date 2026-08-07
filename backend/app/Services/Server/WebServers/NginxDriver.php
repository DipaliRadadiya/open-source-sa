<?php

namespace App\Services\Server\WebServers;

use App\Models\Application;

class NginxDriver extends AbstractWebServerDriver
{
    public function name(): string
    {
        return 'nginx';
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
     * @return array<string, string>
     */
    public function logPaths(Application $application): array
    {
        $dir = rtrim((string) config('server.web_server_drivers.nginx.log_dir', '/var/log/nginx'), '/');
        $name = $this->fileName($application);

        return [
            'access' => "{$dir}/{$name}.access.log",
            'error' => "{$dir}/{$name}.error.log",
        ];
    }
}
