<?php

namespace App\Http\Resources;

use App\Services\Git\Webhooks\WebhookManager;
use App\Services\Server\Applications\ProcessSupervisor;
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
            // How a git app was built. The serving profile is derived from it,
            // but the user chose *this*, so echo it back for the edit form.
            'rendering_type' => $this->rendering_type,
            'status' => $this->status->value,
            'status_title' => $this->status->label(),
            // P1 never provisions, so make the gap explicit rather than
            // letting a "pending" badge imply the site is reachable.
            'deployed' => $this->status->value === 'active',
            // A separate axis from `status`: a healthy site can still be
            // paused (vhost swapped to the "unavailable" page) without its
            // provisioning state changing. The dashboard's enable/disable
            // action switches on this, not on `status`.
            'is_disabled' => $this->disabled_at !== null,
            'disabled_at' => $this->disabled_at?->format('d-m-Y H:i:s'),

            // Whole-site HTTP Basic Auth. The username is shown because the
            // Security screen needs to display "currently protected as
            // :username" without asking the user to remember what they set;
            // the password hash never leaves the server — there is nothing
            // useful it could tell the frontend, and it is one bcrypt hash
            // closer to a credential no API response should carry.
            'basic_auth_enabled' => (bool) $this->basic_auth_enabled,
            'basic_auth_username' => $this->basic_auth_enabled ? $this->basic_auth_username : null,

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

            // Whether this application runs a process of its own, and what
            // systemd says about it *right now*. Null for PHP and static sites,
            // which have nothing to run — render no controls for those rather
            // than controls that would do nothing.
            'has_process' => app(ProcessSupervisor::class)->runs($this->resource),
            'process' => $this->when(
                app(ProcessSupervisor::class)->runs($this->resource),
                fn () => app(ProcessSupervisor::class)->status($this->resource),
            ),

            // Git source: a null account means a public repository, which
            // needs no credentials.
            'git_account_id' => $this->git_account_id,
            'repository' => $this->repository,
            'repository_url' => $this->repository_url,
            'branch' => $this->branch,

            // Deploy-on-push. The secret is shown because the user has to paste
            // it into their repository settings and will come back for it — the
            // same reasoning as the System User password. `url` is built here so
            // the frontend never assembles it (and never gets the path wrong on
            // a panel served under a different host).
            'webhook' => [
                'enabled' => (bool) $this->webhook_enabled,
                'provider' => $this->webhook_provider,
                'url' => $this->webhook_identifier
                    ? url("/api/webhooks/deploy/{$this->webhook_identifier}")
                    : null,
                'secret' => $this->webhook_secret,
                // Which check this secret gets. `token` means a plaintext
                // shared value — only GitLab has one, only because it is the
                // sole thing the panel can generate there, and the UI should
                // offer the stronger signing token rather than let someone sit
                // on the weaker scheme without knowing it exists.
                'verification' => $this->webhook_provider && filled($this->webhook_secret)
                    ? app(WebhookManager::class)
                        ->driver($this->webhook_provider)
                        ->verificationMode((string) $this->webhook_secret)
                    : null,
                'last_delivered_at' => $this->webhook_last_delivered_at?->format('d-m-Y H:i:s'),
                'last_delivered_at_human' => $this->webhook_last_delivered_at?->diffForHumans(),
            ],

            // Cast to an object so an application with no extra settings
            // serializes as `{}` and not `[]`. PHP cannot tell an empty map
            // from an empty list, so json_encode picks the array — and the
            // shape of this field would then depend on whether it happens to
            // be populated, forcing every consumer to handle both.
            // `steps` below is a genuine list and correctly stays `[]`.
            'settings' => (object) ($this->settings ?? []),

            // Provisioning progress, so the UI can show which stage it reached
            // instead of a bare spinner.
            'steps' => $this->steps ?? [],
            'failed_step' => $this->failed_step,
            // What is actually on disk right now — the only honest answer to
            // "which version is running".
            'last_commit' => $this->last_commit,
            'last_deployed_at' => $this->last_deployed_at?->format('d-m-Y H:i:s'),
            'last_deployed_at_human' => $this->last_deployed_at?->diffForHumans(),
            // Quote this to support; the technical detail is in the server-ops
            // log under the same id, never in the response.
            'reference' => $this->reference,

            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
