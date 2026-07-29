<?php

namespace App\Services\Server\WebServers;

/**
 * Config files go straight into sites-enabled (see `web_server_drivers` in
 * config/server.php) — one file per site, nothing to symlink, matching how
 * cron.d files are handled elsewhere in the panel.
 */
class ApacheDriver extends AbstractWebServerDriver
{
    public function name(): string
    {
        return 'apache';
    }

    protected function testCommand(): array
    {
        return ['apachectl', 'configtest'];
    }

    protected function reloadCommand(): array
    {
        return ['systemctl', 'reload', 'apache2'];
    }
}
