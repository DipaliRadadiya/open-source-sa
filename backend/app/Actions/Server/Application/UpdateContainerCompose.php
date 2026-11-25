<?php

namespace App\Actions\Server\Application;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\WebServers\WebServerManager;

/**
 * Replace a container site's compose file and bring it up on the new one.
 *
 * **Why this rolls back.** The user supplies the whole file, so a save can stop the site in ways
 * no validator predicts (an entrypoint that exits, a volume that shadows the app, a missing image
 * tag). The previous file is kept and put back when the new one will not come up.
 *
 * **Why the vhost is rewritten.** With a pasted compose the file chooses the published port and
 * `ContainerSupervisor` records it as `app_port`. nginx proxies to `app_port`, so a changed port
 * without a re-rendered vhost gives a 502 after a save that "worked".
 */
class UpdateContainerCompose
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private ContainerSupervisor $containers,
        private ApplicationProvisioner $provisioner,
        private WebServerManager $webServers,
    ) {}

    public function execute(Application $application, string $compose): Application
    {
        $documentRoot = $this->provisioner->documentRoot($application);

        // What to put back. Empty means the site ran the generated file; rolling back to ""
        // regenerates it from the fields.
        $previous = (string) $application->compose;
        $previousPort = $application->app_port;

        // Exactly one trailing newline (`TrimStrings` strips the editor's); stored as it will be
        // shown back so the editor does not seem to change the file on load.
        $compose = rtrim($compose, "\r\n").PHP_EOL;

        $application->forceFill(['compose' => $compose])->save();

        // Nothing to rewrite for an unprovisioned site; a disabled site must stay offline, so
        // saving must not bring its container up.
        $applies = $application->status === ApplicationStatus::Active
            && $application->disabled_at === null;

        if (! $applies) {
            $this->record($application, $compose, $previous, applied: false, rolledBack: false);

            return $application->fresh(['systemUser']);
        }

        try {
            $this->containers->apply($application, $documentRoot);
        } catch (ProvisioningFailedException $e) {
            $this->rollBack($application, $documentRoot, $compose, $previous, $previousPort, vhost: false);

            throw $e;
        }

        // `apply()` may have moved `app_port`; reload, as a different object wrote it.
        $application = $application->fresh(['systemUser']);

        if ($application->app_port !== $previousPort) {
            // Write, test, then reload (the provisioner's order); unchecked, a failed vhost
            // leaves the old port behind a save that reported success.
            $driver = $this->webServers->driver();

            $steps = [
                'write_config' => fn () => $driver->apply($application, $documentRoot),
                'test_config' => fn () => $driver->test(),
                'reload' => fn () => $driver->reload(),
            ];

            foreach ($steps as $step => $run) {
                $result = $run();

                if ($result->failed()) {
                    $this->rollBack($application, $documentRoot, $compose, $previous, $previousPort, vhost: true);

                    throw new ProvisioningFailedException($step, $result->reference);
                }
            }
        }

        $this->record($application, $compose, $previous, applied: true, rolledBack: false);

        return $application;
    }

    /**
     * Put the working file back and bring the site up on it, plus the vhost if it was already
     * rewritten for the new port.
     *
     * Best effort: if the rollback fails, the original error is still the one to report.
     */
    private function rollBack(
        Application $application,
        string $documentRoot,
        string $compose,
        string $previous,
        mixed $previousPort,
        bool $vhost,
    ): void {
        $application->forceFill(['compose' => $previous, 'app_port' => $previousPort])->save();
        $restored = $application->fresh(['systemUser']);

        try {
            $this->containers->apply($restored, $documentRoot);
        } catch (ProvisioningFailedException) {
            // Intentionally swallowed: best effort.
        }

        if ($vhost) {
            $driver = $this->webServers->driver();

            if ($driver->apply($restored, $documentRoot)->ok && $driver->test()->ok) {
                $driver->reload();
            }
        }

        $this->record($application, $compose, $previous, applied: false, rolledBack: true);
    }

    /**
     * Sizes and outcome, never the file.
     *
     * A compose file holds environment variables, often secrets, and the activity log has a wider
     * audience than the site's editors, so it records that the file changed, not its content.
     */
    private function record(
        Application $application,
        string $compose,
        string $previous,
        bool $applied,
        bool $rolledBack,
    ): void {
        $this->activityLogger->log('application.compose_updated', $application, [
            'name' => $application->name,
            'bytes' => strlen($compose),
            'previous_bytes' => strlen($previous),
            // True when the site ran the panel's generated file until now (the one-way part).
            'took_over_generated' => trim($previous) === '',
            'applied' => $applied,
            'rolled_back' => $rolledBack,
            'app_port' => $application->app_port,
        ]);
    }
}
