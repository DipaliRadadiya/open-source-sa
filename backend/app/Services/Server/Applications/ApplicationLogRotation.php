<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ManagedFile;
use App\Services\Server\WebServers\WebServerManager;
use Throwable;

/**
 * The logrotate policy for everything in a site's `logs/`: one policy per site, run as root.
 *
 * Never `su {user}`: the directory is `root:{user} 0750` and root writes the files, so a site-user
 * policy fails, and one failing policy fails the whole nightly `logrotate.service`. Root is safe
 * because the directory is not writable by the site's group, so nothing can be swapped for a link
 * between rotations. `copytruncate` because systemd and the web servers keep the files open.
 *
 * Files are named, not globbed: Supervisor (`stdout_logfile_maxbytes`) and OpenLiteSpeed
 * (`rollingSize`) rotate their own logs, and rotating those twice would lose lines.
 */
class ApplicationLogRotation
{
    /** Written by systemd for a process site, and by the WAF / PHP. */
    private const SITE_LOGS = ['app.log', 'app-error.log', 'waf-detect.log', 'php-error.log'];

    /** Written by the web server, unless it rotates them itself. */
    private const WEB_SERVER_LOGS = ['access.log', 'error.log', 'access-ssl.log', 'error-ssl.log'];

    public function __construct(
        private ManagedFile $files,
        private WebServerManager $webServers,
    ) {}

    /**
     * v7's own name for this file (`{name}-webserver-logs`), so a server coming from v7 has its
     * policy replaced in place: two policies naming one file fail the whole nightly run.
     */
    public function path(Application $application): string
    {
        return '/etc/logrotate.d/'.$application->slug.'-webserver-logs';
    }

    /**
     * Earlier names of this policy (`sv-app-{id}`, `sv-site-{id}`), removed wherever the current
     * one is written: left beside it they would name the same logs twice.
     *
     * @return list<string>
     */
    public function legacyPaths(Application $application): array
    {
        return [
            '/etc/logrotate.d/sv-app-'.$application->id,
            '/etc/logrotate.d/sv-site-'.$application->id,
        ];
    }

    /**
     * Best-effort: a site whose policy could not be written still serves, and the next provision
     * or `sites:resync` writes it again.
     */
    public function write(Application $application): void
    {
        $context = ['feature' => 'application', 'op' => 'log_rotation', 'application' => $application->id];

        $written = $this->files->put($this->path($application), $this->render($application), $context);

        if ($written->ok) {
            foreach ($this->legacyPaths($application) as $legacy) {
                $this->files->delete($legacy, $context);
            }
        }
    }

    public function remove(Application $application): void
    {
        $context = ['feature' => 'application', 'op' => 'log_rotation_remove', 'application' => $application->id];

        foreach ([$this->path($application), ...$this->legacyPaths($application)] as $path) {
            $this->files->delete($path, $context);
        }
    }

    public function render(Application $application): string
    {
        $directory = rtrim($application->logsPath(), '/');
        $names = $this->rotatesOwnLogs() ? self::SITE_LOGS : [...self::WEB_SERVER_LOGS, ...self::SITE_LOGS];
        $paths = implode(' ', array_map(fn (string $name) => "{$directory}/{$name}", $names));

        return <<<CONF
        # Managed by the panel. Rewritten whenever the site's configuration is.
        {$paths} {
            daily
            rotate 14
            maxsize 50M
            missingok
            notifempty
            compress
            delaycompress
            # The web server and systemd hold these open for their whole life;
            # a rename would leave them writing to an inode nobody reads.
            copytruncate
        }

        CONF;
    }

    private function rotatesOwnLogs(): bool
    {
        try {
            return $this->webServers->driver()->name() === 'openlitespeed';
        } catch (Throwable) {
            return false;
        }
    }
}
