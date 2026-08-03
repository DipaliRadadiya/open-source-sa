<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeploymentResource extends JsonResource
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

            'trigger' => $this->trigger->value,
            'trigger_title' => $this->trigger->label(),
            // Null for a webhook: nobody pressed anything, and inventing an
            // actor would be a lie. Render it as System.
            'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'username' => $this->user->username,
            ]),

            'branch' => $this->branch,
            'commit_hash' => $this->commit_hash,
            'commit_short' => $this->shortCommit(),
            'commit_message' => $this->commit_message,
            'commit_author' => $this->commit_author,

            'steps' => $this->steps ?? [],
            'failed_step' => $this->failed_step,
            'reference' => $this->reference,

            // Only on the detail view. A list of fifty deploys each carrying
            // its full build output is a response nobody asked for.
            'output' => $this->when($request->route('deployment') !== null, $this->output),

            'duration' => $this->duration(),
            'started_at' => $this->started_at?->format('d-m-Y H:i:s'),
            'finished_at' => $this->finished_at?->format('d-m-Y H:i:s'),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
