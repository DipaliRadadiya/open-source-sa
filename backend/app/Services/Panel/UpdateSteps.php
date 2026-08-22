<?php

namespace App\Services\Panel;

/**
 * Which step list describes this installation's updates.
 *
 * Two flows exist at once and have different steps: the legacy in-place
 * checkout, and the release-directory flow. The progress bar reads its total
 * from here rather than from one of them, because a panel that has been
 * migrated and is drawing "step 8 of 14" against the wrong list is reporting
 * something that is not true — and worse, would show a step name it has no
 * translation for.
 *
 * One place, so the runner and the UI cannot disagree about which flow is
 * running. They did not disagree before only because there was one flow.
 */
class UpdateSteps
{
    public function __construct(private PanelLayout $layout) {}

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return $this->layout->isReleased()
            ? ReleaseUpdateScript::STEPS
            : UpdateScript::STEPS;
    }

    /**
     * Position in the sequence, 1-based, or null for a step this flow does not
     * have.
     *
     * Null rather than a guess: a row written by the other flow — an update
     * that ran before a migration, still in the history — has steps this list
     * does not contain, and inventing a number for it would draw a progress
     * bar for a sequence that never happened.
     */
    public function numberOf(?string $step): ?int
    {
        if ($step === null) {
            return null;
        }

        $index = array_search($step, $this->all(), true);

        return $index === false ? null : $index + 1;
    }

    public function total(): int
    {
        return count($this->all());
    }
}
