<?php

namespace App\Services\Panel;

/**
 * Which step list describes this installation's updates.
 *
 * Two flows exist with different steps: the legacy in-place checkout and the
 * release-directory flow. The progress bar reads its total from here, so the runner and
 * the UI cannot disagree about the flow (a wrong list shows untranslated step names).
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
     * Position in the sequence, 1-based, or null for a step this flow does not have.
     *
     * Null, not a guess: history rows written by the other flow have steps this list lacks.
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
