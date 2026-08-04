<?php

namespace App\Http\Resources;

use App\Services\Panel\UpdateScript;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PanelUpdateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_title' => $this->status->label(),
            'current_step' => $this->current_step,
            'current_step_title' => $this->current_step === null
                ? null
                : __('panel_update.steps.'.$this->current_step),
            // Position in the sequence, so the UI can draw a progress bar
            // without hardcoding the step list.
            'step_number' => $this->stepNumber(),
            'total_steps' => count(UpdateScript::STEPS),
            'from_version' => $this->from_version,
            'to_version' => $this->to_version,
            'from_commit' => $this->from_commit,
            'to_commit' => $this->to_commit,
            // A classified key, never raw stderr — the detail is in the log.
            'reason' => $this->reason,
            'reason_title' => $this->reason === null
                ? null
                : __('panel_update.reasons.'.$this->reason),
            'rolled_back' => $this->rolled_back,
            'reference' => $this->reference,
            'started_at' => $this->started_at?->format('d-m-Y H:i:s'),
            'started_at_human' => $this->started_at?->diffForHumans(),
            'finished_at' => $this->finished_at?->format('d-m-Y H:i:s'),
            'finished_at_human' => $this->finished_at?->diffForHumans(),
        ];
    }

    private function stepNumber(): ?int
    {
        if ($this->current_step === null) {
            return null;
        }

        $index = array_search($this->current_step, UpdateScript::STEPS, true);

        return $index === false ? null : $index + 1;
    }
}
