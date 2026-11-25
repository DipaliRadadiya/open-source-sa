<?php

namespace App\Contracts;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\ProvisionProgress;

/**
 * Installs a marketplace application (WordPress, Moodle, Mautic, ...) into an already provisioned
 * site: fetch the software, configure it, run its setup.
 *
 * Adding an app is one class here plus one config entry.
 */
interface SiteInstaller
{
    /** The site type this installs, e.g. `wordpress`. */
    public function siteType(): string;

    /** Does this application need a database created for it first? */
    public function needsDatabase(): bool;

    /**
     * The engines this application can actually use, most preferred first.
     *
     * Only consulted when it needs a database. An unsupported engine fails inside the
     * application's own setup with a confusing driver error.
     *
     * @return array<int, string>
     */
    public function acceptedEngines(): array;

    /**
     * The oldest version of an accepted engine this application will run on, keyed by engine
     * name; empty when it has no such requirement.
     *
     * Separate from {@see acceptedEngines()}: that asks whether the application can use the
     * engine at all, this asks whether the server's version is new enough.
     *
     * @return array<string, string>
     */
    public function minimumEngineVersions(): array;

    /**
     * The command that runs this application, for the ones that are a process rather than a
     * directory of files; null for everything served by PHP.
     *
     * The panel writes it because the path it needs is only known once the document root is.
     */
    public function startCommand(Application $application, string $documentRoot): ?string;

    /**
     * Reconcile the application's own canonical URL with what the vhost serves.
     *
     * Called after certificate and primary-domain changes as well as during
     * brownfield resync. Installers that persist no public URL inherit a no-op.
     *
     * @throws ProvisioningFailedException
     */
    public function syncUrl(Application $application, string $url): void;

    /**
     * Install into the prepared document root.
     *
     * The database (when needed) is already created and passed in `$context` as `database`,
     * `db_user` and `db_password`, so an installer never talks to the database engine itself.
     *
     * Returns nothing: progress is recorded as it happens by {@see ProvisionProgress}, through the
     * one method every installer command passes through.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws ProvisioningFailedException
     */
    public function install(Application $application, string $documentRoot, array $context): void;

    /**
     * Run once the application has started and answered its first request.
     *
     * For what only a running application can do. n8n and Uptime Kuma have no setup command: the
     * first visitor would become administrator, so the installer creates that account here,
     * before the site is reported ready.
     *
     * @throws ProvisioningFailedException
     */
    public function afterStart(Application $application, string $documentRoot): void;
}
