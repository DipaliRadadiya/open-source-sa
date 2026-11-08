<?php

namespace App\Services\Server\WebServers;

use App\Models\Application;
use App\Services\Server\Applications\SiteRootLock;
use App\Services\Server\ServerOps;

/**
 * The site's `conf/{web server}` folder, which its vhost loads (v7 layout).
 *
 * v7 made it the site user's (its agent ran `chown -R {user}`), and the web
 * server reads every `*.conf` in it as root. Anyone who can write as that user
 * — the user, or a hacked site — could then add web server config, and
 * `access_log /etc/cron.d/x` is root code. So the folder and what is in it are
 * root's here: the rules keep working exactly as they did, and the site user
 * can read them but no longer change them (Dipali, 2026-11-08, D1a).
 *
 * Links are removed, never followed: a link the user planted where the folder
 * should be would otherwise point root's `chown -R` anywhere on the box.
 */
class SiteConfFolder
{
    public function __construct(
        private ServerOps $serverOps,
        private SiteRootLock $rootLock,
    ) {}

    public function ensure(Application $application, string $webServer): void
    {
        $folder = $application->siteConfPath();
        $conf = dirname($folder);
        $paths = [$conf, $folder];

        // OpenLiteSpeed reads rewrite rules from their own subfolder, inside the
        // vhost's `rewrite {}` block — v7's `rewrites/`.
        if ($webServer === 'openlitespeed') {
            $paths[] = $folder.'/rewrites';
        }

        foreach ($paths as $path) {
            if ($this->serverOps->probe(['test', '-L', $path], $this->context($application, 'site_conf_link_check'))->ok) {
                $this->serverOps->run(['rm', '-f', $path], $this->context($application, 'site_conf_unlink'));
            }

            // `conf` is an entry of the site root, which is immutable once
            // locked ({@see SiteRootLock}).
            $path === $conf
                ? $this->rootLock->ensureDirectory($application, $path, $this->context($application, 'site_conf_mkdir'))
                : $this->serverOps->run(['mkdir', '-p', $path], $this->context($application, 'site_conf_mkdir'));

            $this->serverOps->run(['chown', '-h', 'root:root', $path], $this->context($application, 'site_conf_chown'));
            $this->serverOps->run(['chmod', '0755', $path], $this->context($application, 'site_conf_chmod'));
        }

        // What v7 left in it: still loaded, now root's. `-h` and no `-L`, so a
        // link inside is re-owned itself and never followed.
        $this->serverOps->run(['chown', '-R', '-h', 'root:root', $folder], $this->context($application, 'site_conf_chown_contents'));
        $this->serverOps->run(['chmod', '-R', 'go-w', $folder], $this->context($application, 'site_conf_chmod_contents'));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Application $application, string $op): array
    {
        return ['feature' => 'application', 'op' => $op, 'application' => $application->id];
    }
}
