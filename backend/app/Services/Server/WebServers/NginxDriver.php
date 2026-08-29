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
