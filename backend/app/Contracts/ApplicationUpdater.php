<?php

namespace App\Contracts;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;

/**
 * A site type whose own software the panel can update in place — v7's
 * one-click "Update" for Moodle, Mautic, Statamic, Craft CMS, Uptime Kuma,
 * phpMyAdmin, n8n and Node-RED.
 *
 * Implemented by the site's installer, because an update needs exactly what
 * the install already knows: where the code lives, which PHP or Node it runs
 * on, and how to put files into the site as the site's own user.
 *
 * Updates stay inside the installed major release (branch, for Moodle). A
 * newer major is reported, never applied: majors break plugins and themes,
 * and are a migration the user should choose to make.
 */
interface ApplicationUpdater
{
    /** The installed version, read from the site's own files; null when it cannot be read. */
    public function installedVersion(Application $application): ?string;

    /**
     * The newest release within the installed major (`latest`) and the newest
     * release of all (`newest`). Either is null when it cannot be found out.
     *
     * @return array{latest: ?string, newest: ?string}
     */
    public function availableVersions(Application $application, string $installed): array;

    /**
     * Update the site from `$from` to `$to`, a version `availableVersions()`
     * returned as `latest`.
     *
     * @throws ProvisioningFailedException
     */
    public function update(Application $application, string $from, string $to): void;
}
