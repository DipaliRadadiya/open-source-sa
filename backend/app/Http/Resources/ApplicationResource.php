<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'domain' => $this->domain,
            'site_type' => $this->site_type,
            'site_type_title' => __("application.types.{$this->site_type}.title"),
            'serving_profile' => $this->serving_profile,
            'status' => $this->status->value,
            'status_title' => $this->status->label(),
            // P1 never provisions, so make the gap explicit rather than
            // letting a "pending" badge imply the site is reachable.
            'deployed' => $this->status->value === 'active',

            'system_user' => $this->whenLoaded('systemUser', fn () => $this->systemUser ? [
                'id' => $this->systemUser->id,
                'username' => $this->systemUser->username,
            ] : null),

            'php_version' => $this->php_version,
            'node_version' => $this->node_version,
            'app_port' => $this->app_port,
            'web_root' => $this->web_root,
            'build_command' => $this->build_command,
            'start_command' => $this->start_command,

            // Git source: a null account means a public repository, which
            // needs no credentials.
            'git_account_id' => $this->git_account_id,
            'repository' => $this->repository,
            'repository_url' => $this->repository_url,
            'branch' => $this->branch,

            'settings' => $this->settings ?? [],

            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
