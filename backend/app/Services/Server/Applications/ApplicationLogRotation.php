<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ManagedFile;
use App\Services\Server\WebServers\WebServerManager;
use Throwable;

/**
 * The logrotate policy for everything in a site's `logs/` (LOG-01).
 *
 * Two things were wrong, and the first broke the second's neighbours:
 *
 *  - Node sites had a policy (`sv-app-{id}`) that ran as the site user
 *    (`su {user}`). The directory is `root:{user} 0750` and the files are
 *    written by root (systemd opens `append:` targets in PID 1, the web
 *    server's master opens its logs), so the user could not touch them and
 *    logrotate failed — and a failure in one policy fails the whole nightly
 *    `logrotate.service`, every other policy on the server included.
 *  - Every other site had no policy at all: its access and error logs grew
 *    forever on the disk every site shares.
 *
 * So: one policy per site, for all its logs, run as root. Root is right here
 * because the user cannot use it to reach anything — the directory is not
 * writable by the site's group, so nothing in it can be swapped for a link
 * between rotations. `copytruncate` because systemd and the web servers hold
 * these files open for their whole life; a rename would leave them writing
 * to an inode nobody reads.
 *
 * Files are named rather than globbed. Supervisor rotates its own worker logs
 * (`stdout_logfile_maxbytes`), and OpenLiteSpeed rotates the access and error
 * logs it writes itself (`rollingSize`); rotating those twice would lose lines
 * and fight over the same names.
 */
class ApplicationLogRotation
{
    /** Written by systemd for a process site, and by the WAF / PHP. */
    private const SITE_LOGS = ['app.log', 'app-error.log', 'waf-detect.log', 'php-error.log'];

    /** Written by the web server, unless it rotates them itself. */
    private const WEB_SERVER_LOGS = ['access.log', 'error.log'];

    public function __construct(
        private ManagedFile $files,
        private WebServerManager $webServers,
    ) {}

    public function path(Application $application): string
    {
        return '/etc/logrotate.d/sv-site-'.$application->id;
    }

    /**
     * The policy `ProcessSupervisor` used to write for Node sites — the one
     * that failed the nightly run. Removed wherever the new one is written:
     * left in place it would still fail, and it names `app.log` a second
     * time, which logrotate rejects on its own.
     */
    public function legacyPath(Application $application): string
    {
        return '/etc/logrotate.d/sv-app-'.$application->id;
    }

    /**
     * Best-effort, like the directory it belongs to: a site whose policy could
     * not be written still serves, and the next provision or `sites:resync`
     * writes it again.
     */
    public function write(Application $application): void
    {
        $context = ['feature' => 'application', 'op' => 'log_rotation', 'application' => $application->id];

        $written = $this->files->put($this->path($application), $this->render($application), $context);

        if ($written->ok) {
            $this->files->delete($this->legacyPath($application), $context);
        }
    }

    public function remove(Application $application): void
    {
        $context = ['feature' => 'application', 'op' => 'log_rotation_remove', 'application' => $application->id];

        $this->files->delete($this->path($application), $context);
        $this->files->delete($this->legacyPath($application), $context);
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
