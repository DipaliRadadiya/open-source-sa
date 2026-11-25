<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\WebServers\WebServerManager;
use Throwable;

/**
 * The one directory every log for a site lands in: `{appRoot}/logs`.
 *
 * **Owned by root, group the site user, mode 0750.** Both halves are load-bearing.
 *
 * The site user is in the group, so it can read every log of its own site.
 *
 * The site user does **not** own the directory, so it cannot create, delete or replace anything
 * in it. The files here are opened by root (nginx/Apache master process, systemd `append:`
 * targets, php-fpm `error_log` before it drops to the pool user). Write permission on a directory
 * is permission to unlink, so a user-owned directory would let a compromised site replace
 * `access.log` with a symlink to `/etc/cron.d/anything` and have a root process append
 * attacker-chosen text into it. Never `chown` this directory to the site user.
 *
 * Every writer is a root process handing a descriptor down, so nothing running as the site user
 * ever needs to create a file in here.
 */
class ApplicationLogDirectory
{
    public function __construct(
        private ServerOps $serverOps,
        private WebServerManager $webServers,
        private SiteRootLock $rootLock,
        private ApplicationLogRotation $rotation,
    ) {}

    /**
     * Ensure the directory exists with the right owner, group and mode.
     *
     * Idempotent; ownership is re-asserted every run (not only on creation), so a user-owned
     * `logs/` from an older provision repairs itself on the next provision or `sites:resync`.
     *
     * Returns whether a group grant was actually made, which tells the caller the web server
     * must restart — see {@see admitLogWriter()}. False is normal: always on nginx and Apache,
     * and on OpenLiteSpeed every run after the first.
     */
    public function ensure(Application $application): bool
    {
        $directory = $application->logsPath();
        $group = $application->systemUser->username;

        $context = [
            'feature' => 'application',
            'op' => 'log_dir',
            'application' => $application->id,
            'path' => $directory,
        ];

        // Created with the site root's immutable flag lifted if missing ({@see SiteRootLock});
        // the chown/chmod below touch only this directory and need nothing lifted.
        $this->rootLock->ensureDirectory($application, $directory, $context);

        foreach ([
            // root owns it; the site's group can read and traverse.
            ['chown', "root:{$group}", $directory],
            ['chmod', '0750', $directory],
        ] as $command) {
            $this->serverOps->run($command, $context, timeout: 15);
        }

        // Every site with a log directory gets a rotation policy; this runs on create and on
        // every `sites:resync`.
        $this->rotation->write($application);

        return $this->admitLogWriter($application, $group);
    }

    /**
     * Let the web server's own account into the group, when it is the one that opens the log files.
     *
     * Null for nginx and Apache (a root master process opens the log), so nothing is granted
     * there. Only OpenLiteSpeed answers: its workers run as `nobody` and open the logs themselves.
     *
     * Group membership, never `chmod o+x`: traversal for "other" would let every local account
     * read every site's access log (IPs, URLs, query strings). This admits one named account and
     * leaves the mode alone, so the site user still cannot unlink what a privileged process
     * appends to.
     *
     * 🔴 Ordering matters: supplementary groups are read when a process starts, so a grant added
     * after the web server started is inert until it restarts. This runs inside `apply()`, before
     * the restart that publishes the vhost.
     *
     * Best-effort: a web server the panel cannot configure has no driver to ask, and that must not
     * stop the directory being created.
     *
     * Returns true only when the membership was actually added. `sites:resync` reloads the web
     * server only when config text changed, and this grant changes none, so the caller restarts
     * on this result. Ask first: `gpasswd -a` also succeeds for an existing member, and restarting
     * the web server on every resync would be worse.
     */
    private function admitLogWriter(Application $application, string $group): bool
    {
        try {
            $user = $this->webServers->driver()->logWriterUser();
        } catch (Throwable) {
            return false;
        }

        if ($user === null || $user === $group) {
            return false;
        }

        $context = [
            'feature' => 'application',
            'op' => 'log_dir_writer',
            'application' => $application->id,
            'user' => $user,
        ];

        // An unreadable answer counts as "not a member": costs one redundant `gpasswd` and
        // restart, whereas the opposite default could skip a needed grant.
        $groups = $this->serverOps->run(['id', '-nG', $user], $context, timeout: 15);

        if ($groups->ok && in_array($group, preg_split('/\s+/', trim($groups->output())) ?: [], true)) {
            return false;
        }

        return $this->serverOps->run(['gpasswd', '-a', $user, $group], $context, timeout: 15)->ok;
    }
}
