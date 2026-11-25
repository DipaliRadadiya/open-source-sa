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
        // Fail closed: the output is sensitive, so non-admins never get it if this is reused.
        $output = $request->user()?->isAdmin()
            ? app(PanelUpdateOutput::class)->read($this->resource)
            : ['content' => '', 'truncated' => false];

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_title' => $this->status->label(),
            'current_step' => $this->current_step,
            'current_step_title' => $this->stepTitle($this->current_step),
            // Position in the sequence, so the UI can draw a progress bar.
            'step_number' => $this->stepNumber(),
            'total_steps' => app(UpdateSteps::class)->total(),
            // A rehearsal: nothing was changed, so `to_version` is null.
            'dry_run' => (bool) $this->dry_run,
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
     * Asked of the flow this installation actually runs: the two flows have different steps.
     */
    private function stepNumber(): ?int
    {
        return app(UpdateSteps::class)->numberOf($this->current_step);
    }

    /**
     * A step's sentence, never its key: `__()` returns the key when no line exists, and a progress
     * screen showing an identifier is worse than one showing nothing.
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
     * The release flow appends `:migrated` to the failed step when the migration had already run
     * (code rolled back, schema not). Any step after `migrate` can carry it, so the clause is
     * appended instead of duplicating every reason.
     */
    private function reasonTitle(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        [$code, $suffix] = array_pad(explode(':', $reason, 2), 2, null);

        $key = 'panel_update.reasons.'.$code;
        // Fall back to the generic sentence, not the raw key.
        $title = Lang::has($key) ? __($key) : __('panel_update.reasons.unknown');

        // The legacy flow's rollback restores the database when the update changed it.
        return match ($suffix) {
            'migrated' => $title.' '.__('panel_update.reason_migrated'),
            'db_restored' => $title.' '.__('panel_update.reason_db_restored'),
            'db_not_restored' => $title.' '.__('panel_update.reason_db_not_restored'),
            default => $title,
        };
    }
}
