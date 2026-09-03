<?php

namespace App\Contracts;

use App\Models\Application;

/**
 * The type-specific 20% of staging — everything else (creating the
 * `Application` row, provisioning its vhost, the maintenance-mode window,
 * the mandatory pre-push backup, copying files) is identical no matter what
 * is being staged and lives in `StagingManager`. Only "does this need a
 * database, and how do I make it point at the right one" differs per type,
 * which is exactly what this contract asks a site type to answer.
 */
interface StagingStrategy
{
    /**
     * Files are already copied and the staging site is already provisioned
     * (real vhost, real document root) by the time this runs — this is only
     * the database half: clone it, point the app's own config at the new
     * one, and do whatever URL rewriting the app's storage format needs.
     */
    public function create(Application $production, Application $staging): void;

    /**
     * Files are already pushed back to production by the time this runs —
     * `full` also needs the database pushed and the URL rewrite reversed;
     * `files` needs nothing here at all.
     */
    public function push(Application $production, Application $staging, string $mode): void;

    /**
     * Paths rsync must never carry between the two sites, in either
     * direction.
     *
     * This lives on the strategy rather than in `StagingManager` because the
     * answer is entirely type-specific: which file holds the database
     * credentials, which file pins the site's own URL, and which files the
     * panel wrote onto staging *because* it is staging. The manager cannot
     * know any of that, and when it tried, the WordPress answers were absent
     * — a push copied staging's `wp-config.php` onto production, pointing the
     * live site at the staging database and overriding its URL with staging's.
     *
     * @return array<int, string> rsync `--exclude` patterns
     */
    public function syncExcludes(): array;
}
