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
}
