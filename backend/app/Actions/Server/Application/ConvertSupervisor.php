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
 * Adoption deliberately does not do this. It cannot: taking a server over must
 * not restart a customer's site, so applications arrive still running under the
 * daemon that was already running them, and stay there until someone chooses
 * otherwise. This is that choice, made explicitly, at a moment the user picked.
 *
 * **It restarts the application, and it must not pretend otherwise.** There is
 * no zero-downtime path here: the port has to be released by one supervisor
 * before the other can bind it. Blue-green — start the new one on a spare port
 * and flip the proxy — was rejected, because it requires two instances of the
 * application alive simultaneously and adoption is precisely when we know least
 * about whether that is safe. n8n and Node-RED both poll the same execution
 * database; two instances means duplicate workflow runs, duplicate webhooks,
 * duplicate charges. Anything with a scheduler, a queue consumer or a lock file
 * has the same problem.
 *
 * So: one restart, announced, with a rollback that puts the application back
 * the way it was if the new supervisor cannot serve a page.
 *
 * The order matters and is the whole design:
 *
 * 1. stop the old supervisor, releasing the port
 * 2. start the new one
 * 3. ask the application for a page
 * 4. only then discard the old supervisor's definition
 *
 * Step 4 last is what makes step 3 recoverable. Until `pm2 delete` runs, the
 * daemon still holds a complete definition of this application, and that
 * definition *is* the rollback.
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
     * It needs an entrypoint systemd can execute. The old panel stored a
     * package-manager invocation (`npm run start`) and rewrote it on the way to
     * PM2, so for many adopted applications there is nothing here we could put
     * in an `ExecStart` — and deriving one by reading `package.json` and
     * guessing through `next build && next start` is how an adopted site fails
     * to come back. The user supplies it; we validate and prove it.
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

        // Releases the port. Stopped rather than deleted: the daemon keeps the
        // definition, which is what rollback restores from.
        $this->legacyPm2->stop($application);

        // In memory only. `ProcessSupervisor` routes on this attribute, so it
        // has to change before `apply()` — but persisting it now would leave a
        // row claiming a unit that does not exist yet if the next line throws.
        $application->supervisor_mode = SupervisorMode::Systemd;

        try {
            $this->supervisor->apply($application, $documentRoot);

            // `is-active` inside `apply()` says the process is running. This
            // says it answers — the distinction that a NodeBB with uncompiled
            // assets taught: up, stable, and 500 on every request.
            $this->readiness->verify($application);
        } catch (ProvisioningFailedException $e) {
            $this->rollback($application, $processName);

            throw $e;
        }

        // Proven. Now the old definition can go — `delete` then `save`, never
        // `cleardump`, so the other applications this user owns keep theirs.
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
     * The unit comes out *before* the mode reverts: `ProcessSupervisor::remove`
     * routes on the mode, so reverting first would send the removal to the PM2
     * driver and leave our half-built unit enabled on disk — a unit that starts
     * a second copy of this application at the next boot.
     */
    private function rollback(Application $application, string $processName): void
    {
        $this->supervisor->remove($application);

        $application->supervisor_mode = SupervisorMode::Pm2;
        $application->pm2_process_name = $processName;

        // Back under the daemon, which still has the definition because the
        // conversion only ever stopped it.
        $this->legacyPm2->start($application);

        $this->activityLogger->log('application.supervisor_convert_failed', $application, [
            'name' => $application->name,
        ]);
    }
}
