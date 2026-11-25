<?php

namespace App\Contracts;

use App\Models\Application;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\SystemUsers\HomeDirectoryAccess;

/**
 * One web server the panel can configure. Only one runs on a server (they fight over port 80),
 * so exactly one driver is active at a time.
 *
 * A driver owns its config paths, syntax, test and reload commands: nothing outside a driver may
 * name a config file or a reload command.
 */
interface WebServerDriver
{
    /** nginx | apache | openlitespeed */
    public function name(): string;

    /** Absolute path of the site's config file. */
    public function configPath(Application $application): string;

    /**
     * Every config file the site has, by path, with what it should hold — null for a file that
     * must not exist (v7's split HTTPS file).
     *
     * @return array<string, ?string>
     */
    public function configFiles(Application $application, string $documentRoot): array;

    /** The live (sites-enabled) path for a written file. */
    public function enabledPathFor(string $availablePath): string;

    /**
     * Make a written file live.
     *
     * @param  array<string, mixed>  $context
     */
    public function link(string $availablePath, array $context = []): ServerOpsResult;

    /**
     * Put the site's configuration in place.
     *
     * A driver owns this because "the site's config" is not one file everywhere: OpenLiteSpeed
     * also needs entries in the shared httpd_config.conf.
     */
    public function apply(Application $application, string $documentRoot): ServerOpsResult;

    /**
     * Create every directory the rendered config will name.
     *
     * On the contract because `apply()` is not the only writer: `sites:resync` also renders and
     * writes the file.
     *
     * Returns whether preparation changed anything the running web server would only notice on a
     * restart. A new directory is not such a change (the config test reads the filesystem), but
     * adding the web server's account to a site's log group is: supplementary groups are read at
     * process start. `sites:resync` reloads on `true` even when the config text is unchanged.
     */
    public function ensureDirectories(Application $application): bool;

    /**
     * The inverse of `apply()`, and the rollback when a config test fails. `rm -f` is not enough
     * for a driver whose site lives partly in a file shared with every other site.
     */
    public function remove(Application $application): ServerOpsResult;

    /**
     * Render the site config for the application's serving profile.
     *
     * Profiles, not site types: `php` and `static` between them cover every PHP app we ship.
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
     * Belongs to the driver because the three web servers put them in different places (nginx and
     * Apache under /var/log named after the domain, OpenLiteSpeed inside the site's directory) and
     * the vhost template that creates them is the driver's.
     *
     * @return array<string, string> keyed `access` and `error`
     */
    public function logPaths(Application $application): array;

    /**
     * The OS account that opens the per-site log files, or null when they are
     * opened by a root master process before privileges are dropped.
     *
     * On the contract because {@see ApplicationLogDirectory} is built on the answer. That
     * directory is `root:{site user} 0750`, which assumes every writer is a root master process
     * handing a descriptor down. nginx and Apache do; OpenLiteSpeed's workers run as `nobody`,
     * could not traverse it and wrote no logs (so the per-site fail2ban jail never saw a line).
     *
     * Null is the safe default because it grants nothing; a driver returning a user is asking for
     * that account to be let in.
     */
    public function logWriterUser(): ?string;

    /**
     * The account the web server's workers run as, which has to pass through
     * a system user's home to serve the static files of the sites inside it.
     * Homes are closed to every other account ({@see HomeDirectoryAccess})
     * and opened to this one by ACL. Null: not known, and then homes are left
     * open rather than closed on a guess.
     */
    public function siteReaderUser(): ?string;

    /**
     * What answers a name no site on this server claims, or null when this web server chooses its
     * default some other way.
     *
     * Left to the web server, an unknown name went to the first site it loaded, with that site's
     * certificate. `files` path => contents, `links` the symlinks the web server reads them
     * through, `legacy` the panel's earlier copies to remove, `package` the one whose untouched
     * conffile may be written over.
     *
     * @return array{files: array<string, string>, links: array<string, string>, legacy: list<string>, package: ?string}|null
     */
    public function catchAllConfig(): ?array;

    /**
     * The site's bot blocker / 8G / force-HTTPS files in its conf folder, v7's
     * names, path => contents (null: the panel's own copy must go).
     *
     * @return array<string, ?string>
     */
    public function siteConfFiles(Application $application, string $documentRoot): array;

    /** Whether a configFiles() path is one of those. */
    public function isSiteConfPath(Application $application, string $path): bool;
}
