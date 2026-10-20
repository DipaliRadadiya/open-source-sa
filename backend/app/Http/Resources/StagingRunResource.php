<?php

namespace App\Http\Resources;

use App\Models\StagingRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property StagingRun $resource
 */
class StagingRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $run = $this->resource;

        // A row whose worker died is reported as what it is, not as running
        // for ever; the next push or pull is not blocked by it either.
        $stranded = $run->isStranded();

        return [
            'id' => $run->id,
            'kind' => $run->kind,
            'mode' => $run->mode,
            'backup' => $run->backup,
            'status' => $stranded ? StagingRun::FAILED : $run->status,
            // What the endpoint itself would have answered before it ran on
            // the queue: `message`, `code` or `reason`, `reference`, and for a
            // failed backup `backup_id` / `backup_reason`.
            'error' => $stranded
                ? ['message' => __('errors/application.staging_run_lost'), 'code' => 'staging_run_lost']
                : $run->error,
            'error_status' => $stranded ? 500 : $run->error_status,
            'backup_id' => $run->backup_id,
            'created_at' => $run->created_at?->format('d-m-Y H:i:s'),
            'started_at' => $run->started_at?->format('d-m-Y H:i:s'),
            'started_at_human' => $run->started_at?->diffForHumans(),
            'finished_at' => $run->finished_at?->format('d-m-Y H:i:s'),
            'finished_at_human' => $run->finished_at?->diffForHumans(),
        ];
    }
}
