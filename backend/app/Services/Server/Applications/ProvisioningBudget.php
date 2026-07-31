<?php

namespace App\Services\Server\Applications;

use App\Models\Application;

/**
 * How long a provision or a deploy is allowed to take.
 *
 * This exists because the two numbers have to agree and they were written in
 * different files: installers declare 900–1800s in `config/server.php`
 * (Nextcloud is a 280 MB download, `npm install` on NodeBB is worse), while the
 * job that runs them carried a flat 300. With `pcntl` loaded Laravel enforces
 * its timeout with SIGALRM, so every one of those installs was killed at five
 * minutes — mid-download, the record marked `failed` at step `worker`, and
 * whatever was running left orphaned. Nextcloud could never install at all.
 *
 * So the job asks the work how long it needs rather than guessing:
 * the slow part's own timeout, plus one overhead allowance for the steps
 * around it.
 *
 * The same number bounds `retry_after` in `config/queue.php` — the queue's
 * reservation window has to outlast the longest job, or a job still running
 * becomes eligible for a second worker halfway through changing the server.
 */
class ProvisioningBudget
{
    public function __construct(private InstallerManager $installers) {}

    /**
     * Provisioning: the site's own installer is the slow part.
     *
     * Resolved from the site type rather than the Application, so the caller
     * can be the job's constructor (which has an id) or a test asking about a
     * card that has never been created.
     */
    public function forSiteType(?string $siteType): int
    {
        return $this->overhead() + $this->installerTimeout($siteType);
    }

    /**
     * The budget for an application by id, for a job that only has one.
     *
     * A missing application means it was deleted between dispatch and now;
     * the job handles that itself, and the timeout is irrelevant.
     */
    public function forApplication(?int $applicationId): int
    {
        $siteType = $applicationId === null
            ? null
            : Application::whereKey($applicationId)->value('site_type');

        return $this->forSiteType($siteType === null ? null : (string) $siteType);
    }

    /**
     * Deploying: clone and build are the slow parts, and they run in sequence.
     *
     * The previous 900 was exactly `git_timeout` + `build_timeout`, which left
     * nothing for the chown and the restart that follow — a build using its
     * full allowance killed the job just after finishing.
     */
    public function forDeploy(): int
    {
        return $this->overhead()
            + (int) config('server.git_timeout', 300)
            + (int) config('server.build_timeout', 600);
    }

    /**
     * The largest budget any job can ask for, for the bound `retry_after` has
     * to clear. Derived from the installer list, so adding a slower app raises
     * it (and trips the test that checks the queue config) instead of silently
     * exceeding it.
     */
    public function longest(): int
    {
        $timeouts = [$this->forDeploy()];

        foreach (array_keys((array) config('server.installers', [])) as $siteType) {
            $timeouts[] = $this->forSiteType((string) $siteType);
        }

        return max($timeouts);
    }

    /**
     * A site type with no installer still gets the default allowance: the
     * steps around it are the same, and `installer_timeout` is what its
     * `ServerOps` calls would use.
     */
    private function installerTimeout(?string $siteType): int
    {
        $default = (int) config('server.installer_timeout', 300);

        if ($siteType === null || ! $this->installers->hasInstaller($siteType)) {
            return $default;
        }

        return (int) config("server.installers.{$siteType}.timeout", $default);
    }

    private function overhead(): int
    {
        return (int) config('server.job_overhead', 120);
    }
}
