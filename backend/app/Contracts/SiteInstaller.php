<?php

namespace App\Contracts;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;

/**
 * Installs a marketplace application into a site that has already been
 * provisioned — WordPress, Moodle, Mautic and the rest.
 *
 * Provisioning (directory, vhost, reload) is done by the time an installer
 * runs, so an installer only concerns itself with the application's own
 * software: fetch it, configure it, run its setup.
 *
 * Adding an app to the marketplace is one class here plus one config entry.
 */
interface SiteInstaller
{
    /** The site type this installs, e.g. `wordpress`. */
    public function siteType(): string;

    /** Does this application need a database created for it first? */
    public function needsDatabase(): bool;

    /**
     * Install into the prepared document root.
     *
     * The database (when needed) is already created and passed in `$context`
     * as `database`, `db_user` and `db_password`, so an installer never talks
     * to the database engine itself.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, string> the steps completed, in order
     *
     * @throws ProvisioningFailedException
     */
    public function install(Application $application, string $documentRoot, array $context): array;
}
