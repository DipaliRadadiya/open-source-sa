<?php

namespace App\Contracts;

use App\Models\Application;

/**
 * The type-specific part of staging. Everything else (the `Application` row, vhost,
 * maintenance window, pre-push backup, file copy) lives in `StagingManager`; only the database
 * handling differs per site type.
 */
interface StagingStrategy
{
    /**
     * Runs after files are copied and staging is provisioned: only the database half. Clone it,
     * point the app's config at the clone, and rewrite URLs as the app's storage needs.
     */
    public function create(Application $production, Application $staging): void;

    /**
     * Runs after files are pushed to production. `full` also pushes the database and reverses
     * the URL rewrite; `files` needs nothing here.
     */
    public function push(Application $production, Application $staging, string $mode): void;

    /**
     * The reverse: runs after live's files are copied onto staging. `database`/`full` copy live's
     * database into staging and rewrite URLs the other way. Every mode must leave staging still
     * staging (own config, hidden from search engines, mail trapped) or throw, so the manager
     * rolls staging back.
     */
    public function pull(Application $production, Application $staging, string $mode): void;

    /**
     * Paths rsync must never carry between the two sites, in either direction.
     *
     * Type-specific: the files holding database credentials, pinning the site's URL, or written
     * onto staging because it is staging. Missing one makes a push copy staging's config (e.g.
     * `wp-config.php`) onto production.
     *
     * @return array<int, string> rsync `--exclude` patterns
     */
    public function syncExcludes(): array;

    /**
     * Paths rsync must carry between the two sites *additively*: copied across, never deleted
     * from the destination.
     *
     * Uploaded media is the case: in a `--delete` pass, a photo added on production meanwhile
     * would be erased, but excluding it outright leaves database rows pointing at files that never
     * crossed. A second pass without `--delete` does both. Consequence: deleting a file in
     * staging does not delete it on production.
     *
     * @return array<int, string> paths relative to the document root
     */
    public function mergePaths(): array;
}
