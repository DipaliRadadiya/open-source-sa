<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\ServerOps;

/**
 * The one directory every log for a site lands in: `{appRoot}/logs`.
 *
 * **Owned by root, group the site user, mode 0750.** That combination is the
 * whole point of this class, and both halves of it are load-bearing.
 *
 * The site user is in the group, so it can read every log — which is the
 * reason the logs moved out of `/var/log/nginx` and out of `.panel` at all. A
 * developer whose site is throwing 500s needs its error log, and needing root
 * for that on your own site is absurd.
 *
 * The site user does **not** own the directory, so it cannot create, delete or
 * replace anything in it. That matters because the files here are opened by
 * root: nginx and Apache open their access logs in the master process, systemd
 * opens `StandardOutput=append:` targets in PID 1, and php-fpm opens a pool's
 * `error_log` before dropping to the pool user. Write permission on a
 * directory is permission to unlink what is inside it, so a user-owned log
 * directory lets a compromised site delete `access.log`, put a symlink to
 * `/etc/cron.d/anything` in its place, and have a root process append
 * attacker-chosen request text into it. That is the reason distributions keep
 * web server logs under root-owned `/var/log` in the first place, and it does
 * not stop being true because the directory moved.
 *
 * The corollary — every writer here is a root master process handing a
 * descriptor down — is what makes a root-owned directory workable at all.
 * Nothing running *as* the site user ever needs to create a file in here.
 * {@see vps-security-hardening-research §9: "files outside the application
 * tree are not owned by the app user".}
 *
 * `ProcessSupervisor::ensureLogDirectory()` used to `chown {user}:{user}` this
 * same path, because when it was written the only things in it were that
 * application's own process logs. Adding the web server's logs to the same
 * directory is what makes the ownership matter.
 */
class ApplicationLogDirectory
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Ensure the directory exists with the right owner, group and mode.
     *
     * Idempotent, and deliberately re-asserts ownership every time rather than
     * only on creation: a server that provisioned sites before this existed
     * has a user-owned `logs/`, and it repairs itself on the next provision or
     * `sites:resync` without anyone running anything by hand.
     */
    public function ensure(Application $application): void
    {
        $directory = $application->logsPath();
        $group = $application->systemUser->username;

        foreach ([
            ['mkdir', '-p', $directory],
            // root owns it; the site's group can read and traverse.
            ['chown', "root:{$group}", $directory],
            ['chmod', '0750', $directory],
        ] as $command) {
            $this->serverOps->run(
                $command,
                [
                    'feature' => 'application',
                    'op' => 'log_dir',
                    'application' => $application->id,
                    'path' => $directory,
                ],
                timeout: 15,
            );
        }
    }
}
