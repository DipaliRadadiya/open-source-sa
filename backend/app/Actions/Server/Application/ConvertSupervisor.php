<?php

namespace App\Actions\Server\Application;

use App\Enums\SupervisorMode;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\HttpReadinessCheck;
use App\Services\Server\Applications\LegacyPm2Driver;
use App\Services\Server\Applications\ProcessSupervisor;

/**
 * Move an adopted application off the old panel's PM2 daemon and onto a unit
 * of ours — or back.
 *
 * Adoption does not do this itself: taking a server over must not restart a customer's site,
 * so applications stay under the old daemon until the user explicitly chooses to convert.
 *
 * It restarts the application: the port must be released by one supervisor before the other
 * can bind it. No blue-green: two live instances duplicate scheduled work, webhooks and queue
 * consumers (n8n, Node-RED). If the new supervisor cannot serve a page, the application is
 * put back the way it was.
 *
 * The order is the design:
 *
 * 1. stop the old supervisor, releasing the port
 * 2. start the new one
 * 3. ask the application for a page
 * 4. only then discard the old supervisor's definition
 *
 * Until `pm2 delete` runs, the daemon still holds the definition, and that is the rollback.
 */
class ConvertSupervisor
{
    public function __construct(
        private ProcessSupervisor $supervisor,
        private LegacyPm2Driver $legacyPm2,
        private HttpReadinessCheck $readiness,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * Whether this application can move to a unit of ours.
     *
     * Needs an entrypoint systemd can execute. The old panel stored a package-manager command
     * (`npm run start`), and guessing one from `package.json` is how an adopted site fails to
     * come back, so the user supplies it and we validate it.
     */
    public function convertible(Application $application): bool
    {
        return $this->supervisor->legacy($application) && filled($application->start_command);
    }

    /**
     * @throws ProvisioningFailedException
     */
    public function toSystemd(Application $application, string $documentRoot): void
    {
        if (! $this->supervisor->legacy($application)) {
            return;
        }

        if (! filled($application->start_command)) {
            throw new ProvisioningFailedException('convert_no_entrypoint', '');
        }

        $processName = $this->legacyPm2->processName($application);

        // Releases the port. Stopped, not deleted: rollback restores from the daemon's definition.
        $this->legacyPm2->stop($application);

        // In memory only: `ProcessSupervisor` routes on it, so it must change before `apply()`,
        // but saving it now would claim a unit that does not exist yet if the next line throws.
        $application->supervisor_mode = SupervisorMode::Systemd;

        try {
            $this->supervisor->apply($application, $documentRoot);

            // `apply()` only proves the process is running; this proves it answers.
            $this->readiness->verify($application);
        } catch (ProvisioningFailedException $e) {
            $this->rollback($application, $processName);

            throw $e;
        }

        // Proven. Now the old definition can go: `delete` then `save`, never `cleardump`, which
        // would drop the user's other applications.
        $this->legacyPm2->remove($application);

        $application->pm2_process_name = null;
        $application->save();

        $this->activityLogger->log('application.supervisor_converted', $application, [
            'name' => $application->name,
            'from' => SupervisorMode::Pm2->value,
            'to' => SupervisorMode::Systemd->value,
        ]);
    }

    /**
     * Put it back the way it was.
     *
     * The unit comes out *before* the mode reverts: `ProcessSupervisor::remove` routes on the
     * mode, and reverting first would leave our half-built unit enabled (a second copy at boot).
     */
    private function rollback(Application $application, string $processName): void
    {
        $this->supervisor->remove($application);

        $application->supervisor_mode = SupervisorMode::Pm2;
        $application->pm2_process_name = $processName;

        // The daemon still has the definition: the conversion only stopped it.
        $this->legacyPm2->start($application);

        $this->activityLogger->log('application.supervisor_convert_failed', $application, [
            'name' => $application->name,
        ]);
    }
}
