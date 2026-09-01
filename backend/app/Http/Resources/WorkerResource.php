<?php

namespace App\Http\Resources;

use App\Models\Worker;
use App\Services\Server\Applications\WorkerSupervisor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Worker $resource
 */
class WorkerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = app(WorkerSupervisor::class)->status($this->resource);

        return [
            'id' => $this->resource->id,
            'application_id' => $this->resource->application_id,
            'name' => $this->resource->name,
            'command' => $this->resource->command,
            'kind' => $this->resource->kind,
            'kind_title' => __('worker.kinds.'.$this->resource->kind),
            'directory' => $this->resource->directory,
            'processes' => $this->resource->processes,
            'stop_wait_seconds' => $this->resource->stop_wait_seconds,
            'auto_restart' => $this->resource->auto_restart,
            'restart_on_deploy' => $this->resource->restart_on_deploy,
            'enabled' => $this->resource->enabled,

            // Read from systemd on every request, never stored. "3 of 4
            // running" is a real state that a single green dot would hide.
            'running' => $status['running'],
            'state' => $status['state'],
            'state_title' => __('worker.states.'.$status['state']),

            // The journal identifier, so the logs screen can be linked to
            // without the frontend constructing a unit name.
            'log_identifier' => 'sv-worker-'.$this->resource->slug,

            'created_at' => $this->resource->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->resource->created_at?->diffForHumans(),
        ];
    }
}
