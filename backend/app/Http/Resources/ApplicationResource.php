<?php

namespace App\Http\Resources;

use App\Services\Git\Webhooks\WebhookManager;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\WebServers\WebServerManager;
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
            // Built here, not by the client. Three frontend components each
            // assembled `https://${domain}` themselves, which is a broken link
            // on every site that has no certificate yet — and every site has
            // no certificate for the first few minutes of its life.
            'url' => $this->resource->url(),
            // Where the site is on disk. Two fields because they are two
            // different directories on Craft and Statamic, and a cron job
            // pointed at the wrong one runs a file that is not there and
            // reports nothing.
            //
            // `document_root` is what the web server serves — the path to show
            // somebody. `path` is where the application's own CLI runs, which
            // is the value a `{path}` placeholder in a cron command or a deploy
            // script expands to.
            'document_root' => $this->resource->documentRoot(),
            'path' => $this->resource->codePath(),
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

            // Which AI crawlers are 403'd for this site. `title` is the same
            // plain-language label the Bot Blocker screen's radio option
            // uses, so a dashboard badge and the settings screen never say
            // two different things for the same value.
            'ai_bot_policy' => $this->ai_bot_policy->value,
            'ai_bot_policy_title' => $this->ai_bot_policy->title(),
            // This site's own additions to, and exemptions from, that list.
            // `bot_allowed` holds agents the built-in list would block and
            // this site does not want blocked, so the screen can show them
            // as exemptions rather than as a second block list.
            'bot_blocked' => $this->whenLoaded('botRules', fn () => $this->botRules->where('type', 'block')->pluck('value')->values()),
            'bot_allowed' => $this->whenLoaded('botRules', fn () => $this->botRules->where('type', 'allow')->pluck('value')->values()),

            // The 8G Firewall. `waf_categories` is null-resolved to "all six"
            // here too, so the frontend never has to know that null means
            // the same thing as the full list — one place decides that.
            // Whether this server's web server can enforce it at all. Hide the
            // control when false rather than letting the user turn on a
            // firewall that inspects nothing — enabling it answers 422, but
            // finding that out by pressing the button is not a design.
            'waf_supported' => app(WebServerManager::class)->driver()->supportsWaf(),
            'waf_enabled' => (bool) $this->waf_enabled,
            'waf_mode' => $this->waf_mode->value,
            'waf_mode_title' => $this->waf_mode->title(),
            'waf_categories' => $this->wafActiveCategories(),
            'waf_exceptions' => $this->whenLoaded('wafRules', fn () => $this->wafRules->where('type', 'exception')->pluck('value')->values()),
            'waf_custom_rules' => $this->whenLoaded('wafRules', fn () => $this->wafRules->where('type', 'block')->pluck('value')->values()),

            /*
             * Derived from the jail, not from the `fail2ban_enabled` column.
             *
             * That column had exactly one writer — an action nothing called,
             * which itself called a manager method that does not exist — so it
             * was `false` on every application that has ever existed. The
             * fail2ban screen reads the jail columns and was right; the
             * dashboard read this and said "Off" for sites with a running
             * jail. Two representations of one fact, and the orphaned one won
             * wherever it was consulted.
             *
             * `fail2ban_jail_name` is on this row already, so both screens now
             * answer from the same column at no extra cost. It is set when the
             * jail is written and cleared when it is removed.
             *
             * This says a jail is *configured for this site*, not that
             * fail2ban is running — that is a server-wide fact and belongs to
             * the services list. Live jail state (banned IPs, counters) is
             * deliberately absent too: it needs a fail2ban-client call per
             * request, which a list resource is not the place for.
             * See GET .../fail2ban.
             */
            'fail2ban_enabled' => $this->fail2ban_jail_name !== null,

            // Staging is just another application row — `is_staging` is the
            // whole marker. `has_staging` lets the production site's own
            // dashboard show "Create staging" vs "View staging" without a
            // second request; it is only computed when `staging` is loaded,
            // the same `whenLoaded` discipline every relation here follows.
            'is_staging' => $this->isStaging(),
            'production_application_id' => $this->production_application_id,
            'has_staging' => $this->whenLoaded('staging', fn () => $this->staging !== null),
            // Informational only — unlike `production_application_id`,
            // nothing reads this to decide behavior. It only answers "where
            // did this come from" on the clone's own dashboard.
            'cloned_from_application_id' => $this->cloned_from_application_id,

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
            // When the CURRENT run started, restamped on every retry —
            // `created_at` is the row's birthday and is wrong for elapsed
            // time the moment anyone retries. Null on a site that has
            // never been provisioned.
            'provisioning_started_at' => $this->provisioning_started_at?->format('d-m-Y H:i:s'),
            'provisioning_started_at_human' => $this->provisioning_started_at?->diffForHumans(),
            // What is actually on disk right now — the only honest answer to
            // "which version is running".
            'last_commit' => $this->last_commit,
            'last_deployed_at' => $this->last_deployed_at?->format('d-m-Y H:i:s'),
            'last_deployed_at_human' => $this->last_deployed_at?->diffForHumans(),
            // Quote this to support; the technical detail is in the server-ops
            // log under the same id, never in the response.
            'reference' => $this->reference,

            // Cached directory size (bytes). Refreshed after every deploy.
            // Null means not yet computed.
            // Always present, null when nobody has measured it yet. It used to
            // be omitted entirely in that case, so the column vanished from
            // some rows and the frontend could not tell "not measured" from
            // "the field does not exist on this endpoint".
            //
            // Nothing measures this on a timer — a `du` walks every inode, so
            // doing it for every site on a schedule costs real disk on the
            // machine serving the sites. It is set when a deploy happens and
            // when somebody asks for it, which is why the time it was taken
            // travels with it. A size with no date reads as current.
            'directory_size_bytes' => $this->directory_size_bytes,
            'directory_size_measured_at' => $this->directory_size_updated_at?->format('d-m-Y H:i:s'),
            'directory_size_measured_at_human' => $this->directory_size_updated_at?->diffForHumans(),

            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
