<?php

namespace App\Contracts;

use App\Models\Application;
use App\Services\Server\ServerOpsResult;

/**
 * One web server the panel can configure. Only one runs on a server — they
 * fight over port 80 — so exactly one driver is active at a time.
 *
 * A driver knows its own config paths, syntax, test command and reload
 * command. Nothing outside a driver may name a config file or a reload
 * command, which is what lets nginx / apache / OpenLiteSpeed differ without
 * the rest of the feature caring.
 */
interface WebServerDriver
{
    /** nginx | apache | openlitespeed */
    public function name(): string;

    /** Absolute path of the site's config file. */
    public function configPath(Application $application): string;

    /**
     * Put the site's configuration in place.
     *
     * A driver owns this rather than the provisioner writing a file itself,
     * because "the site's config" is not one file everywhere. nginx and Apache
     * drop a file in a directory; OpenLiteSpeed needs that *and* entries in the
     * shared httpd_config.conf, which no `tee` can express.
     */
    public function apply(Application $application, string $documentRoot): ServerOpsResult;

    /**
     * Take it back out again — the inverse of `apply()`, and the rollback when
     * a config test fails. `rm -f` is not enough for a driver whose site lives
     * partly inside a file shared with every other site.
     */
    public function remove(Application $application): ServerOpsResult;

    /**
     * Render the site config for the application's serving profile.
     *
     * Profiles, not site types: `php` and `static` between them cover every
     * PHP app we ship, so adding WordPress #19 costs no template work.
     */
    public function renderConfig(Application $application, string $documentRoot): string;

    /**
     * Check the configuration is valid. **Must** be called before any reload —
     * reloading a broken config takes every other site on the box down with it.
     */
    public function test(): ServerOpsResult;

    /** Apply the configuration without dropping connections. */
    public function reload(): ServerOpsResult;

    /**
     * Where this driver writes one application's access and error logs.
     *
     * Belongs to the driver for the same reason configPath() does: the three
     * web servers put them in three different places — nginx and Apache under
     * /var/log named after the domain, OpenLiteSpeed inside the site's own
     * directory — and the vhost template that creates them is the driver's.
     * Anything else would be a second copy of that decision, free to drift
     * from the template the moment either changes.
     *
     * @return array<string, string> keyed `access` and `error`
     */
    public function logPaths(Application $application): array;
}
