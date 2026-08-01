<?php

namespace App\Actions\Server\Application;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\ManagedFile;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Rewrite one application's web-server configuration and put it live.
 *
 * Provisioning already does this as part of a longer sequence; a domain change
 * needs the same three steps on their own — write, test, reload — with the same
 * rule that a failed test puts the previous configuration back.
 *
 * Rolling back to the previous *contents* rather than removing the file, which
 * is what provisioning does: a new site has nothing to fall back to, but a live
 * site does, and taking its vhost away over a rejected domain would turn a
 * mistyped hostname into an outage.
 */
class ApplyVhost
{
    public function __construct(
        private WebServerManager $webServers,
        private ApplicationProvisioner $provisioner,
    ) {}

    /**
     * @throws ProvisioningFailedException
     */
    public function execute(Application $application): void
    {
        $driver = $this->webServers->driver();
        $documentRoot = $this->provisioner->documentRoot($application);

        // Keep whatever is currently serving, so a rejected config can be
        // undone rather than merely deleted.
        $previous = $driver->renderConfig($application->fresh(['domains']), $documentRoot);

        $written = $driver->apply($application->load('domains'), $documentRoot);

        if ($written->failed()) {
            throw new ProvisioningFailedException('write_config', $written->reference);
        }

        $test = $driver->test();

        if ($test->failed()) {
            $this->restore($application, $previous);

            throw new ProvisioningFailedException('test_config', $test->reference);
        }

        $reload = $driver->reload();

        if ($reload->failed()) {
            throw new ProvisioningFailedException('reload', $reload->reference);
        }
    }

    /**
     * Put the previous configuration back and reload, so the site keeps
     * serving what it was serving a moment ago.
     */
    private function restore(Application $application, string $previous): void
    {
        $driver = $this->webServers->driver();

        app(ManagedFile::class)->put(
            $driver->configPath($application),
            $previous,
            ['feature' => 'application', 'op' => 'restore_config', 'application' => $application->id],
        );

        // Only reload if the restored config passes — reloading a broken one
        // takes every other site on the box down with it.
        if ($driver->test()->ok) {
            $driver->reload();
        }
    }
}
