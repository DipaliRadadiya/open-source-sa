<?php

namespace App\Http\Resources;

use App\Services\Panel\PanelUpdateOutput;
use App\Services\Panel\UpdateSteps;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;

class PanelUpdateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // This Resource is currently used only by admin routes, but keep the
        // sensitive operational output fail-closed if it is reused elsewhere.
        $output = $request->user()?->isAdmin()
            ? app(PanelUpdateOutput::class)->read($this->resource)
            : ['content' => '', 'truncated' => false];

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_title' => $this->status->label(),
            'current_step' => $this->current_step,
            'current_step_title' => $this->stepTitle($this->current_step),
            // Position in the sequence, so the UI can draw a progress bar
            // without hardcoding the step list.
            'step_number' => $this->stepNumber(),
            'total_steps' => app(UpdateSteps::class)->total(),
            'from_version' => $this->from_version,
            'to_version' => $this->to_version,
            'from_commit' => $this->from_commit,
            'to_commit' => $this->to_commit,
            // A classified key, never raw stderr — the detail is in the log.
            'reason' => $this->reason,
            'reason_title' => $this->reasonTitle($this->reason),
            'rolled_back' => $this->rolled_back,
            'reference' => $this->reference,
            'output' => $output['content'],
            'output_truncated' => $output['truncated'],
            'started_at' => $this->started_at?->format('d-m-Y H:i:s'),
            'started_at_human' => $this->started_at?->diffForHumans(),
            'finished_at' => $this->finished_at?->format('d-m-Y H:i:s'),
            'finished_at_human' => $this->finished_at?->diffForHumans(),
        ];
    }

    /**
     * Asked of the flow this installation actually runs.
     *
     * Two flows exist with different steps. Counting against the wrong one
     * reports a position that is not true, and a migrated panel would draw its
     * progress against a sequence it is not following.
     */
    private function stepNumber(): ?int
    {
        return app(UpdateSteps::class)->numberOf($this->current_step);
    }

    /**
     * A step's sentence, never its key.
     *
     * `__()` returns the key it was given when no line exists, so a step added
     * to UpdateScript::STEPS without a line here rendered the literal string
     * `panel_update.steps.swap` on the progress screen. Two steps shipped that
     * way. The test beside this asserts the gap is closed; this makes the
     * failure mode harmless if it ever reopens, because a progress screen
     * showing an identifier is worse than one showing nothing.
     */
    private function stepTitle(?string $step): ?string
    {
        if ($step === null) {
            return null;
        }

        return Lang::has('panel_update.steps.'.$step)
            ? __('panel_update.steps.'.$step)
            : null;
    }

    /**
     * The failure sentence, including the part a rollback could not undo.
     *
     * The release flow appends `:migrated` to the failed step when the
     * migration had already run — the code is back on the previous version and
     * the schema is not, which is the single most important thing to say and
     * was being said by rendering `panel_update.reasons.swap:migrated` as text.
     * Any step after `migrate` can carry the suffix, so the clause is one
     * sentence appended rather than nine duplicated reasons.
     */
    private function reasonTitle(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        [$code, $suffix] = array_pad(explode(':', $reason, 2), 2, null);

        $key = 'panel_update.reasons.'.$code;
        // Falls back to the generic sentence rather than the key: the reader
        // already knows it failed, and an untranslated identifier tells them
        // less than "for an unknown reason" does.
        $title = Lang::has($key) ? __($key) : __('panel_update.reasons.unknown');

        return $suffix === 'migrated'
            ? $title.' '.__('panel_update.reason_migrated')
            : $title;
    }
}
