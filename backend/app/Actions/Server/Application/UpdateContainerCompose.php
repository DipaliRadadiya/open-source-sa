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
 * **Why this rolls back.** Every other apply path in the panel changes one field;
 * this one hands the user the whole file, so a save can stop the site in ways no
 * validator can predict — an entrypoint that exits, a volume that shadows the
 * app, an image tag that does not exist. A save that leaves a site down and the
 * panel holding the text that broke it is worse than a save that refuses, so the
 * previous file is kept and put back when the new one will not come up. The ini
 * editor already works this way for the same reason.
 *
 * **Why the vhost is rewritten.** With a pasted compose the panel does not choose
 * the published port — the file does, and `ContainerSupervisor` records it on the
 * application. nginx proxies to `app_port`, so editing the port in the file and
 * not re-rendering the vhost points the web server at a port nothing is listening
 * on: a 502 caused by a save that "worked". Reconciled here because this is the
 * only screen where the port can move without the panel choosing it.
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

        // What to put back. Empty for a site that was running the generated file,
        // which is a real state and not a missing value: rolling back to "" is
        // rolling back to "generate it from the fields", which is exactly right.
        $previous = (string) $application->compose;
        $previousPort = $application->app_port;

        // Exactly one trailing newline. `TrimStrings` has already taken the one the
        // editor sent, and a file that does not end in a newline is a POSIX text
        // file that isn't — `tail`, `cat` of two files and a few YAML tools all
        // behave differently for it. Normalised here rather than at the boundary
        // because this is the only writer, and stored in the form it will be shown
        // back in so the editor does not appear to change the file on load.
        $compose = rtrim($compose, "\r\n").PHP_EOL;

        $application->forceFill(['compose' => $compose])->save();

        // Nothing on disk to rewrite for a site that was never provisioned, and a
        // disabled site is deliberately offline — bringing its container up as a
        // side effect of saving a file would put the site back online.
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

        // `apply()` may have moved `app_port` to whatever the file publishes.
        // Read it fresh rather than trusting the in-memory model, which was
        // written by a different object.
        $application = $application->fresh(['systemUser']);

        if ($application->app_port !== $previousPort) {
            // Written, TESTED, then reloaded — the provisioner's order (DS-09).
            // Unchecked, a vhost that did not write or did not pass `nginx -t`
            // left the old one proxying to the old port behind a save that
            // reported success: the 502 this reconcile exists to prevent.
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
     * Put the working file back and bring the site up on it — and, when the
     * vhost was already rewritten for the new port, the vhost too.
     *
     * Best effort: if the rollback itself fails the site is already down and
     * the original error is still the one worth reporting, so it is not
     * replaced by a second one about the restore.
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
            // Intentionally swallowed — see above.
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
     * A compose file holds environment variables, and people put secrets in
     * those. The activity log is readable by a wider audience than the people who
     * may edit a site, so it records that the file changed and what happened —
     * which is the question asked after an incident — and not what it said.
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
            // True when the site was running the panel's generated file until now.
            // The one-way part of this change, and worth being able to see later.
            'took_over_generated' => trim($previous) === '',
            'applied' => $applied,
            'rolled_back' => $rolledBack,
            'app_port' => $application->app_port,
        ]);
    }
}
