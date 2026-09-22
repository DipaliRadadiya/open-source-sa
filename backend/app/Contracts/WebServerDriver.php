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
     * Create every directory the rendered config will name.
     *
     * On the contract because `apply()` is not the only writer: `sites:resync`
     * renders and writes the same file itself, and used to do so without any
     * of this preparation.
     *
     * Returns whether preparation changed anything the running web server
     * would only notice on a restart. Creating a directory is not such a
     * change — the config test reads the filesystem when it runs. Adding the
     * web server's account to a site's log group is: supplementary groups are
     * read at process start, so until the workers restart the grant does
     * nothing at all. `sites:resync` decides whether to reload on this,
     * because a site whose config text is unchanged still needs the restart
     * when this answers true.
     */
    public function ensureDirectories(Application $application): bool;

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

    /**
     * The OS account that opens the per-site log files, or null when they are
     * opened by a root master process before privileges are dropped.
     *
     * On the contract because {@see ApplicationLogDirectory} is built on the
     * answer and cannot ask the question itself. That directory is
     * `root:{site user} 0750` for a reason its own docblock states -- the site
     * can read its logs but cannot unlink and replace a file a root process is
     * appending to -- and the reason rests on a premise: "every writer here is
     * a root master process handing a descriptor down". nginx and Apache do
     * that. OpenLiteSpeed does not, and so wrote nothing at all: its workers
     * run as `nobody`, which is neither the owner nor in the group, and could
     * not traverse the directory to open the file. Measured on a live box --
     * two requests answered 200 and access.log stayed at zero bytes, and the
     * per-site fail2ban jail watching that file could therefore never ban
     * anyone.
     *
     * Null is the safe answer and the default, because it grants nothing. A
     * driver returning a user is asking for that account to be let in.
     */
    public function logWriterUser(): ?string;
}
