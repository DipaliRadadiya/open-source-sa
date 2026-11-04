<?php

namespace App\Http\Resources;

use App\Models\ApplicationRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One install or container redeploy of an application (D3/D5).
 *
 * @property ApplicationRun $resource
 */
class ApplicationRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $run = $this->resource;

        // A run whose worker died is reported as failed, not as running for
        // ever, and it does not block the next one either.
        $stranded = $run->isStranded();

        return [
            'id' => $run->id,
            'kind' => $run->kind,
            'status' => $stranded ? ApplicationRun::FAILED : $run->status,
            'in_flight' => $run->isInFlight(),
            'failed_step' => $stranded ? 'worker' : $run->failed_step,
            'failed_reason' => $run->failed_reason,
            'failed_reason_title' => $run->failed_reason === null ? null : __('application.failure_reason.'.$run->failed_reason),
            'reference' => $run->reference,
            'created_at' => $run->created_at?->format('d-m-Y H:i:s'),
            'started_at' => $run->started_at?->format('d-m-Y H:i:s'),
            'finished_at' => $run->finished_at?->format('d-m-Y H:i:s'),
            'finished_at_human' => $run->finished_at?->diffForHumans(),
        ];
    }
}
