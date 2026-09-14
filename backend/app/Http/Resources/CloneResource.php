<?php

namespace App\Http\Resources;

use App\Models\SiteClone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SiteClone */
class CloneResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $steps = ['provisioning', 'copying_files', 'cloning_database', 'starting_process'];
        $index = $this->resource->current_step === null
            ? false
            : array_search($this->resource->current_step, $steps, true);

        return [
            'id' => $this->resource->id,
            'source_application_id' => $this->resource->source_application_id,
            'source_application_name' => $this->resource->sourceApplication?->name,
            'target_application_id' => $this->resource->target_application_id,
            'name' => $this->resource->name,
            'domain' => $this->resource->domain,
            'status' => $this->resource->status->value,
            'status_title' => $this->resource->status->label(),
            'current_step' => $this->resource->current_step,
            'current_step_title' => $this->resource->current_step === null
                ? null
                : __('clone.current_step.'.$this->resource->current_step),
            'step_number' => $index === false ? null : $index + 1,
            'total_steps' => count($steps),
            'reason' => $this->resource->reason,
            'reason_title' => $this->resource->reason === null
                ? null
                : __('clone.cloning_errors.'.$this->resource->reason),
            'reference' => $this->resource->reference,
            'started_at' => $this->resource->started_at?->format('d-m-Y H:i:s'),
            'started_at_human' => $this->resource->started_at?->diffForHumans(),
            'finished_at' => $this->resource->finished_at?->format('d-m-Y H:i:s'),
            'finished_at_human' => $this->resource->finished_at?->diffForHumans(),

            // The copy's own deploy-on-push endpoint, when the source had one.
            //
            // Here rather than left to the Deployment screen because of the one
            // step nothing on this box can do: a webhook lives in the
            // repository's settings, and one repository webhook posts to one
            // URL. The copy has a different URL, so the user has to add it —
            // and the moment they learn the clone exists is the moment to say
            // so, not whenever they next happen to open Deployment.
            'target_webhook' => $this->whenLoaded('targetApplication', function () {
                $target = $this->resource->targetApplication;

                if (! $target?->webhook_enabled || blank($target->webhook_identifier)) {
                    return null;
                }

                return [
                    'url' => url("/api/webhooks/deploy/{$target->webhook_identifier}"),
                    // Shown for the same reason the Deployment screen shows it:
                    // the user has to paste it into the provider's form, and
                    // will come back for it otherwise.
                    'secret' => $target->webhook_secret,
                    'provider' => $target->webhook_provider,
                ];
            }),
        ];
    }
}
