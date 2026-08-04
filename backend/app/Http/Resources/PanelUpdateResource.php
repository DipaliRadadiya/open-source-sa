<?php

namespace App\Http\Resources;

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
            // What the list polls on, so the frontend does not have to know
            // which statuses are terminal.
            'in_flight' => $this->status->inFlight(),

            // The short, stable code (`unsupported`, `worker`, ...) — the
            // translated sentence lives at `message`. Returning both lets the
            // frontend group rows by reason and the user read a sentence.
            'reason' => $this->reason,
            'message' => $this->message(),
            'reference' => $this->reference,

            // Snapshot of where this update was queued from — the future
            // rollback helper reads these to know what to go back to.
            'from_version' => $this->from_version,
            'from_commit' => $this->from_commit,
            'from_commit_short' => $this->shortCommit($this->from_commit),
            // `to_*` is null today because no helper writes it yet. Reserved
            // so the resource shape is stable for the frontend.
            'to_version' => $this->to_version,
            'to_commit' => $this->to_commit,
            'to_commit_short' => $this->shortCommit($this->to_commit),

            // Null for a system action (none today, but the slot exists for
            // the same reason it exists on `ActivityLogResource::user`).
            'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'username' => $this->user->username,
            ]),

            'duration' => $this->duration(),
            'started_at' => $this->started_at?->format('d-m-Y H:i:s'),
            'started_at_human' => $this->started_at?->diffForHumans(),
            'finished_at' => $this->finished_at?->format('d-m-Y H:i:s'),
            'finished_at_human' => $this->finished_at?->diffForHumans(),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
