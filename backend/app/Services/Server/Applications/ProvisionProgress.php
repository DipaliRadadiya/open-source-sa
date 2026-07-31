<?php

namespace App\Services\Server\Applications;

use App\Models\Application;

/**
 * Records provisioning and deploy steps on the application **as they happen**.
 *
 * `steps[]` was documented as the frontend's progress indicator while an
 * application sits at `provisioning`, but it was only ever assembled in a local
 * variable and written once, on success. So during the twenty minutes a
 * Nextcloud install takes it was `[]`, and after a failure it stayed `[]` — the
 * user could see which step broke and nothing about how far it had got.
 *
 * This is a scoped singleton: the provisioner opens it for one application, and
 * the installers — whose every command already funnels through
 * `AbstractSiteInstaller::run()` — record through the same instance without
 * needing the step list threaded through their signatures. A queue worker runs
 * one job per process, so the single instance is never shared between two
 * applications; `open()` resets it regardless. There is deliberately no
 * `close()` — the container discards the instance at the end of the job, and a
 * teardown method nothing calls is just a promise of cleanup that never runs.
 *
 * Recording is a no-op until something opens it, so an installer exercised
 * directly in a test writes nowhere.
 */
class ProvisionProgress
{
    private ?Application $application = null;

    /** @var array<int, string> */
    private array $steps = [];

    /**
     * Begin recording for an application, discarding anything from a previous
     * run. The empty list is persisted immediately so a retry does not show
     * the previous attempt's progress as if it were this one's.
     */
    public function open(Application $application): void
    {
        $this->application = $application;
        $this->steps = [];

        $this->persist();
    }

    /**
     * Note a completed step.
     *
     * Consecutive repeats collapse: `downloadAndExtract()` runs three commands
     * under `extract`, and the user is being told what is happening, not how
     * many processes it took. A step that legitimately recurs later still
     * appears again, because only the immediately preceding one is compared.
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
     * @return array<int, string>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * Written straight to the row rather than through the model the job holds:
     * the job updates `status` from its own instance afterwards, and two
     * instances of the same record saving different attributes would have the
     * later `save()` overwrite the steps with its stale copy.
     */
    private function persist(): void
    {
        if ($this->application === null) {
            return;
        }

        Application::whereKey($this->application->getKey())
            ->update(['steps' => json_encode($this->steps)]);

        // Keep the caller's instance honest too, so anything reading `steps`
        // off it mid-run sees the same list the database has.
        $this->application->setAttribute('steps', $this->steps);
        $this->application->syncOriginalAttribute('steps');
    }
}
