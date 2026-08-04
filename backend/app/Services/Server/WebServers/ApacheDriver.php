<?php

namespace App\Services\Server\WebServers;

use App\Models\Application;

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

    /**
     * `${APACHE_LOG_DIR}` in the templates, resolved. Apache expands it from
     * envvars at start; we cannot read those, so the directory is configured.
     *
     * @return array<string, string>
     */
    public function logPaths(Application $application): array
    {
        $dir = rtrim((string) config('server.web_server_drivers.apache.log_dir', '/var/log/apache2'), '/');

        return [
            'access' => "{$dir}/{$application->domain}.access.log",
            'error' => "{$dir}/{$application->domain}.error.log",
        ];
    }
}
