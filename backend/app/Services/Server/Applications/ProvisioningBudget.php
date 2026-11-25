<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Recipes\RecipeRegistry;

/**
 * How long a provision or a deploy is allowed to take.
 *
 * The job's timeout must agree with the installer timeouts in `config/server.php`, or Laravel
 * (SIGALRM with `pcntl`) kills a slow install mid-download. So the job asks the work: the slow
 * part's own timeout plus one overhead allowance for the steps around it.
 *
 * The same number bounds `retry_after` in `config/queue.php`: the reservation window must outlast
 * the longest job, or a running job becomes eligible for a second worker.
 */
class ProvisioningBudget
{
    public function __construct(private InstallerManager $installers) {}

    /**
     * Provisioning: the site's own installer is the slow part.
     *
     * Takes the site type, not the Application, so a job constructor or a test can call it.
     */
    public function forSiteType(?string $siteType): int
    {
        return $this->overhead() + $this->installerTimeout($siteType);
    }

    /**
     * The budget for an application by id, for a job that only has one.
     *
     * A missing application was deleted since dispatch; the job handles that itself.
     */
    public function forApplication(?int $applicationId): int
    {
        $siteType = $applicationId === null
            ? null
            : Application::whereKey($applicationId)->value('site_type');

        return $this->forSiteType($siteType === null ? null : (string) $siteType);
    }

    /**
     * Deploying: clone and build run in sequence, plus the overhead for the chown and restart.
     */
    public function forDeploy(): int
    {
        return $this->overhead()
            + (int) config('server.git_timeout', 300)
            + (int) config('server.build_timeout', 600);
    }

    /**
     * Pull and redeploy: `compose pull` and `compose up` in sequence, each under the Docker command
     * timeout, plus the overhead.
     */
    public function forContainerRedeploy(): int
    {
        return $this->overhead() + 2 * (int) config('server.docker.command_timeout', 600);
    }

    /**
     * The largest budget any job can ask for, which `retry_after` has to clear.
     *
     * Derived from the installer list, so a slower app raises it (and trips the queue config test).
     */
    public function longest(): int
    {
        $timeouts = [$this->forDeploy(), $this->forContainerRedeploy()];

        foreach (array_keys((array) config('server.installers', [])) as $siteType) {
            $timeouts[] = $this->forSiteType((string) $siteType);
        }

        // Docker apps are not in the installer list, so their budget is added by name.
        $timeouts[] = $this->forSiteType('docker');

        return max($timeouts);
    }

    /**
     * A site type with no installer still gets the default allowance (`installer_timeout`).
     *
     * Docker apps (every recipe and `docker`) get `server.docker.install_timeout` instead: their
     * first install pulls images inside this job, and an image can be gigabytes.
     */
    private function installerTimeout(?string $siteType): int
    {
        $default = (int) config('server.installer_timeout', 300);

        if ($siteType !== null && $this->isDocker($siteType)) {
            return (int) config('server.docker.install_timeout', 1200);
        }

        if ($siteType === null || ! $this->installers->hasInstaller($siteType)) {
            return $default;
        }

        return (int) config("server.installers.{$siteType}.timeout", $default);
    }

    private function isDocker(string $siteType): bool
    {
        return $siteType === 'docker' || app(RecipeRegistry::class)->has($siteType);
    }

    private function overhead(): int
    {
        return (int) config('server.job_overhead', 120);
    }
}
