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
     * The unit ships `ProtectHome=read-only`. Every site lives in its owner's
     * home and logs to `<site>/logs`, which Apache's root master opens at
     * start, so the first site's reload failed with "Read-only file system:
     * could not open error log file" and **Apache exited — every site and the
     * panel itself with it**. Found on the 26.04 test server.
     *
     * `ReadWritePaths=/home` does not lift it (measured with systemd-run);
     * `ProtectHome=no` does. install.sh writes the same drop-in for new
     * servers; this covers the ones installed before, on their next site or
     * `sites:resync`. The sandbox is fixed when the service starts, so this is
     * the one place a restart, not a reload, is needed — and only the first
     * time: afterwards the unit reports `no` and nothing runs.
     */
    private function ensureHomeWritable(): void
    {
        if ($this->homeWritable) {
            return;
        }

        $context = ['feature' => 'application', 'op' => 'apache_protect_home', 'web_server' => 'apache'];
        $unit = $this->serverOps->run(['systemctl', 'show', 'apache2', '-p', 'ProtectHome', '--value'], $context, 15);

        // Unreadable is left alone: writing a drop-in and restarting Apache on
        // a guess is a bigger risk than the one it removes.
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
        // The application's own `logs/` directory, not ${APACHE_LOG_DIR} —
        // one place per site, readable by the site's owner.
        // {@see Application::logsPath()}
        $dir = $application->logsPath();

        return [
            'access' => "{$dir}/access.log",
            'error' => "{$dir}/error.log",
        ];
    }

    /**
     * Apache: the pattern sits inside `m#...#` in a quoted `SetEnvIfExpr`.
     * `#` is the regex delimiter; `preg_quote` escapes it anyway since PHP 7.3,
     * and it is named as the delimiter only to say so. Apache's config
     * parser only unescapes `\"`, so the quote is the one thing to escape
     * for the directive itself and every other backslash reaches the regex.
     */
    public function wafPattern(string $value): string
    {
        return str_replace('"', '\\"', preg_quote($value, '#'));
    }
}
