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
    /** Set once this process has seen Apache able to write under /home. */
    private bool $homeWritable = false;

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

    public function ensureDirectories(Application $application): bool
    {
        $restart = parent::ensureDirectories($application);

        $this->ensureHomeWritable();

        return $restart;
    }

    /**
     * Lift the read-only /home that Ubuntu 26.04's apache2.service imposes.
     *
     * The unit ships `ProtectHome=read-only`, but every site logs to `<site>/logs` under its
     * owner's home, which Apache's root master opens at start. Without this the first site's
     * reload fails with "Read-only file system" and **Apache exits, taking every site and the
     * panel with it**.
     *
     * `ReadWritePaths=/home` does not lift it; `ProtectHome=no` does. install.sh writes the same
     * drop-in for new servers; this covers older ones on their next site or `sites:resync`. The
     * sandbox is fixed at service start, so this needs a restart, not a reload, and only once.
     */
    private function ensureHomeWritable(): void
    {
        if ($this->homeWritable) {
            return;
        }

        $context = ['feature' => 'application', 'op' => 'apache_protect_home', 'web_server' => 'apache'];
        $unit = $this->serverOps->run(['systemctl', 'show', 'apache2', '-p', 'ProtectHome', '--value'], $context, 15);

        // Unreadable is left alone: restarting Apache on a guess is a bigger risk.
        if ($unit->failed() || in_array(trim($unit->output()), ['', 'no'], true)) {
            $this->homeWritable = $unit->ok;

            return;
        }

        $dropIn = (string) config('server.apache_protect_home_dropin', '/etc/systemd/system/apache2.service.d/panel-site-logs.conf');

        $this->serverOps->run(['mkdir', '-p', dirname($dropIn)], $context, 15);

        if ($this->files->put($dropIn, "[Service]\nProtectHome=no\n", $context)->failed()) {
            return;
        }

        $this->serverOps->run(['systemctl', 'daemon-reload'], $context, 30);

        // Tested first: on a config Apache refuses, a reload keeps serving the old one, but a
        // restart stops Apache for good (every site and the panel down). The next call retries.
        if ($this->test()->failed()) {
            return;
        }

        $this->homeWritable = $this->serverOps->run(['systemctl', 'restart', 'apache2'], $context, 60)->ok;
    }

    /**
     * `${APACHE_LOG_DIR}` in the templates, resolved. Apache expands it from
     * envvars at start; we cannot read those, so the directory is configured.
     *
     * @return array<string, string>
     */
    public function logPaths(Application $application): array
    {
        // The application's own `logs/` directory, readable by the site's owner.
        // {@see Application::logsPath()}
        $dir = $application->logsPath();

        return [
            'access' => "{$dir}/access.log",
            'error' => "{$dir}/error.log",
            // The HTTPS block's, named as v7 names them.
            'access_ssl' => "{$dir}/access-ssl.log",
            'error_ssl' => "{$dir}/error-ssl.log",
        ];
    }

    /**
     * Apache: the pattern sits inside `m#...#` in a quoted `SetEnvIfExpr`; `#` is the delimiter.
     *
     * Apache's config parser turns `\\` into `\` and `\"` into `"` before compiling the regex
     * (httpd `substring_conf`), so both are escaped here. Escaping only the quote lets a typed
     * backslash (`c:\tmp`) reach the regex as an escape of the next character.
     */
    public function wafPattern(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], preg_quote($value, '#'));
    }

    /**
     * v7's Apache has a third: forceHttps.conf, which both of the site's
     * vhosts load ({@see parent::siteConfFiles()}).
     */
    protected function siteConfRules(): array
    {
        return parent::siteConfRules() + ['forceHttps.conf' => ['_force-https', 'forceHttps']];
    }
}
