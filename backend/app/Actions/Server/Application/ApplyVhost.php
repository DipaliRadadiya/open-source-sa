<?php

namespace App\Actions\Server\Application;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Rewrite one application's web-server configuration and put it live: write, test, reload.
 *
 * A failed test restores the previous *contents* rather than removing the file (as provisioning
 * does), so a mistyped domain on a live site cannot become an outage. The contents are read off
 * disk, not re-rendered: the render already holds the new domain, and it would overwrite
 * hand-edited files.
 */
class ApplyVhost
{
    public function __construct(
        private WebServerManager $webServers,
        private ApplicationProvisioner $provisioner,
        private ManagedFile $files,
    ) {}

    /**
     * @throws ProvisioningFailedException
     */
    public function execute(Application $application): void
    {
        $driver = $this->webServers->driver();
        $documentRoot = $this->provisioner->documentRoot($application);

        // Keep whatever is currently serving, so a rejected config can be undone.
        $context = ['feature' => 'application', 'op' => 'read_config', 'application' => $application->id];

        // Every file a change can touch (vhost, HTTPS half, conf-folder rules): a rule file left
        // behind would keep the config test failing for every other site.
        $previous = [];

        foreach (array_keys($driver->configFiles($application->load('domains'), $documentRoot)) as $path) {
            $previous[$path] = $this->files->get($path, $context);
        }

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
     * Put the previous configuration back and reload.
     *
     * Does nothing when the previous contents could not be read: writing an empty file over a live
     * config would take the site down. The rejected config stays on disk, but it is not reloaded.
     */
    /** @param  array<string, ServerOpsResult>  $previous */
    private function restore(Application $application, array $previous): void
    {
        $driver = $this->webServers->driver();
        $context = ['feature' => 'application', 'op' => 'restore_config', 'application' => $application->id];

        foreach ($previous as $path => $read) {
            if ($read->ok) {
                $this->files->put($path, $read->output(), $context);
                $driver->link($path, $context);

                continue;
            }

            // Not there before: remove what the change wrote. Only on "no such file"; any other read
            // failure says nothing about whether the file existed.
            if (! str_contains($read->errorOutput(), 'No such file')) {
                continue;
            }

            if (! $driver->isSiteConfPath($application, $path)) {
                $this->files->delete($driver->enabledPathFor($path), $context);
            }
            $this->files->delete($path, $context);
        }

        if ($driver->test()->ok) {
            $driver->reload();
        }
    }
}
