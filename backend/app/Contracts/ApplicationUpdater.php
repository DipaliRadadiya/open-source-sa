<?php

namespace App\Contracts;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;

/**
 * A site type whose own software the panel can update in place.
 *
 * Implemented by the site's installer, which already knows where the code lives, which
 * PHP or Node it runs on, and how to write files as the site's own user.
 *
 * Updates stay inside the installed major release (branch, for Moodle). A newer major
 * is reported, never applied: it can break plugins and themes and is the user's call.
 */
interface ApplicationUpdater
{
    /** The installed version, read from the site's own files; null when it cannot be read. */
    public function installedVersion(Application $application): ?string;

    /**
     * Newest release within the installed major (`latest`) and newest overall (`newest`).
     * Either is null when unknown.
     *
     * @return array{latest: ?string, newest: ?string}
     */
    public function availableVersions(Application $application, string $installed): array;

    /**
     * Update the site from `$from` to `$to` (an `availableVersions()` `latest`).
     *
     * @throws ProvisioningFailedException
     */
    public function update(Application $application, string $from, string $to): void;
}
