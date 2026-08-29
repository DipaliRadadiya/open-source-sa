<?php

namespace App\Services\Server\WebServers;

use App\Models\Application;

/**
 * Config files are written to sites-available and symlinked into
 * sites-enabled (see `web_server_drivers` in config/server.php) — the
 * standard Debian/Ubuntu layout, and the same one install.sh itself uses for
 * the panel's own vhost.
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
        // The application's own `logs/` directory, not ${APACHE_LOG_DIR} —
        // one place per site, readable by the site's owner.
        // {@see Application::logsPath()}
        $dir = $application->logsPath();

        return [
            'access' => "{$dir}/access.log",
            'error' => "{$dir}/error.log",
        ];
    }
}
