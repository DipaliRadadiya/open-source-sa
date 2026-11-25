<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Models\ApplicationUpdate;

/**
 * Records provisioning and deploy steps on the application **as they happen**, so `steps[]`
 * (the frontend's progress indicator while `provisioning`) shows how far a long install got.
 *
 * A scoped singleton: the provisioner opens it for one application and the installers (whose
 * commands all go through `AbstractSiteInstaller::run()`) record through the same instance.
 * One job per worker process, and `open()` resets it anyway. There is deliberately no
 * `close()`: the container discards the instance at the end of the job.
 *
 * Recording is a no-op until something opens it, so an installer run directly in a test
 * writes nowhere.
 */
class ProvisionProgress
{
    private ?Application $application = null;

    /** @var array<int, string> */
    private array $steps = [];

    private ?ApplicationUpdate $update = null;

    /**
     * Begin recording for an application, discarding any previous run. The empty list is
     * persisted at once so a retry does not show the old attempt's progress.
     */
    public function open(Application $application): void
    {
        $this->application = $application;
        $this->steps = [];

        $this->persist();
    }

    /**
     * Note a completed step. Consecutive repeats collapse (`downloadAndExtract()` runs three
     * commands under `extract`); only the immediately preceding step is compared.
     */
    public function record(string $step): void
    {
        if ($this->application === null) {
            return;
        }

        if ($this->steps !== [] && end($this->steps) === $step) {
            return;
        }

        $this->steps[] = $step;

        $this->persist();
    }

    /**
     * Follow one-click update `$update`: `begin()` keeps its `step` column on the current
     * phase. Separate from `open()` because an update must not reset the application's `steps[]`.
     */
    public function trackUpdate(ApplicationUpdate $update): void
    {
        $this->update = $update;
    }

    /**
     * Note a step that is **starting**, for the update being followed. (`record()` runs after
     * a step succeeds.) A no-op unless `trackUpdate()` was called, so installs and deploys
     * write nothing here.
     */
    public function begin(string $step): void
    {
        if ($this->update === null || $this->update->step === $step) {
            return;
        }

        // Straight to the row, as persist() does, so the job's stale instance cannot overwrite it.
        ApplicationUpdate::whereKey($this->update->getKey())->update(['step' => $step]);

        $this->update->setAttribute('step', $step);
        $this->update->syncOriginalAttribute('step');
    }

    /**
     * @return array<int, string>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * Written straight to the row, not through the job's model: its later `save()` of `status`
     * would overwrite the steps with a stale copy.
     */
    private function persist(): void
    {
        if ($this->application === null) {
            return;
        }

        Application::whereKey($this->application->getKey())
            ->update(['steps' => json_encode($this->steps)]);

        // Keep the caller's instance in sync with the database.
        $this->application->setAttribute('steps', $this->steps);
        $this->application->syncOriginalAttribute('steps');
    }
}
