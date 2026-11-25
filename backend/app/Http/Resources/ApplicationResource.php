<?php

namespace App\Http\Resources;

use App\Enums\CertificateStatus;
use App\Models\Application;
use App\Services\Applications\SiteTypeManager;
use App\Services\Applications\SiteTypeSuggestion;
use App\Services\Applications\SiteTypeText;
use App\Services\Git\Webhooks\WebhookManager;
use App\Services\Recipes\ApplicationOrigin;
use App\Services\Server\Applications\ContainerLiveness;
use App\Services\Server\Applications\ContainerSupervisor;
use App\Services\Server\Applications\ProcessSupervisor;
use App\Services\Server\Runtimes\PhpRuntime;
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
            // The slug is the hostname other containers reach a container site by (the compose network
            // alias, as every generated file names its service `app`).
            'slug' => $this->slug,
            'domain' => $this->domain,
            // Built here, not by the client: a site has no certificate for its first minutes, so a
            // client-built `https://` link would be broken.
            'url' => $this->resource->url(),
            // A certificate was asked for and is not serving yet: `url` is still http, but `https://`
            // fails the TLS handshake for those 10–60 s. True while pending or issuing, so the UI can
            // say "HTTPS is on its way".
            'certificate_pending' => in_array(
                $this->resource->certificate?->status,
                [CertificateStatus::Pending, CertificateStatus::Issuing],
                true,
            ),
            // Where the site is on disk. Two fields: on Craft and Statamic they are different directories.
            // `document_root` is what the web server serves; `path` is where the app's own CLI runs (the
            // `{path}` placeholder in cron commands and deploy scripts). Null when the system user is
            // missing, so the site can still be listed and deleted.
            'document_root' => $this->resource->systemUser ? $this->resource->documentRoot() : null,
            'path' => $this->resource->systemUser ? $this->resource->codePath() : null,
            'site_type' => $this->site_type,
            'site_type_title' => app(SiteTypeText::class)->title((string) $this->site_type),
            'origin' => app(ApplicationOrigin::class)->describe($this->resource),
            // What the last Detect found. `suggested` is null unless relabelling is allowed, so the
            // frontend reimplements none of the rules (see SiteTypeSuggestion).
            'site_type_detection' => app(SiteTypeSuggestion::class)->describe($this->resource),
            'serving_profile' => $this->serving_profile,
            // How a git app was built; the serving profile derives from it, but the user chose this one,
            // so echo it back for the edit form.
            'rendering_type' => $this->rendering_type,
            'status' => $this->status->value,
            'status_title' => $this->status->label(),
            // Explicit, so a "pending" badge does not imply the site is reachable.
            'deployed' => $this->status->value === 'active',
            // Separate from `status`: a healthy site can be paused (vhost swapped to the "unavailable"
            // page). The dashboard's enable/disable action switches on this.
            'is_disabled' => $this->disabled_at !== null,
            'disabled_at' => $this->disabled_at?->format('d-m-Y H:i:s'),

            // Whole-site HTTP Basic Auth. The username is shown for the Security screen; the password
            // hash never leaves the server.
            'basic_auth_enabled' => (bool) $this->basic_auth_enabled,
            'basic_auth_username' => $this->basic_auth_enabled ? $this->basic_auth_username : null,
            // On with no certificate to send visitors to: the browser sends the password in clear. With
            // one, http redirects to https before the prompt, whatever Force HTTPS says.
            'basic_auth_unencrypted' => $this->basic_auth_enabled && $this->resource->scheme() === 'http',
            // False when the app's own client signs in with the Authorization header, which Basic Auth
            // would consume; the UI disables the control. See SiteType::authorizationHeaderAuth().
            'basic_auth_supported' => ! (
                app(SiteTypeManager::class)
                    ->find((string) $this->site_type)
                    ?->authorizationHeaderAuth() ?? false
            ),

            // Which AI crawlers are 403'd for this site. `title` is the label the Bot Blocker screen uses.
            'ai_bot_policy' => $this->ai_bot_policy->value,
            'ai_bot_policy_title' => $this->ai_bot_policy->title(),
            // This site's own additions to, and exemptions from, that list. `bot_allowed` holds agents
            // the built-in list would block but this site does not want blocked.
            'bot_blocked' => $this->whenLoaded('botRules', fn () => $this->botRules->where('type', 'block')->pluck('value')->values()),
            'bot_allowed' => $this->whenLoaded('botRules', fn () => $this->botRules->where('type', 'allow')->pluck('value')->values()),

            // The 8G Firewall. `waf_categories` is resolved here (null means all six), so the frontend
            // need not know that. `waf_supported` is false when the web server cannot enforce it; the UI
            // hides the control (enabling would answer 422).
            'waf_supported' => app(WebServerManager::class)->driver()->supportsWaf(),
            'waf_enabled' => (bool) $this->waf_enabled,
            'waf_mode' => $this->waf_mode->value,
            'waf_mode_title' => $this->waf_mode->title(),
            'waf_categories' => $this->wafActiveCategories(),
            'waf_exceptions' => $this->whenLoaded('wafRules', fn () => $this->wafRules->where('type', 'exception')->pluck('value')->values()),
            'waf_custom_rules' => $this->whenLoaded('wafRules', fn () => $this->wafRules->where('type', 'block')->pluck('value')->values()),

            // Derived from the jail, not the `fail2ban_enabled` column (never written, so it disagreed
            // with the fail2ban screen). Says a jail is configured for this site, not that fail2ban is
            // running; live state (banned IPs, counters) needs a fail2ban-client call per request, so it
            // is left out. See GET .../fail2ban.
            'fail2ban_enabled' => $this->fail2ban_jail_name !== null,

            // `is_staging` is the whole staging marker. `has_staging` lets the production site's
            // dashboard pick "Create" vs "View staging" without a second request; only computed when
            // `staging` is loaded.
            'is_staging' => $this->isStaging(),
            'production_application_id' => $this->production_application_id,
            'has_staging' => $this->whenLoaded('staging', fn () => $this->staging !== null),
            // Informational only: nothing reads this to decide behavior.
            'cloned_from_application_id' => $this->cloned_from_application_id,

            'system_user' => $this->whenLoaded('systemUser', fn () => $this->systemUser ? [
                'id' => $this->systemUser->id,
                'username' => $this->systemUser->username,
            ] : null),

            'php_version' => $this->php_version,
            // The binary this site's PHP version runs from (`/usr/bin/php8.3`,
            // `/usr/local/lsws/lsphp83/bin/php`), so cron jobs run the site's PHP, not the server
            // default. Null for a site that serves no PHP.
            'php_binary' => $this->serving_profile === 'php' && filled($this->php_version)
                ? app(PhpRuntime::class)->binaryPath((string) $this->php_version)
                : null,
            'node_version' => $this->node_version,
            // A switch under way (`switching`) or the last failed one (`failed`, site put back on
            // `node_version`). Null when neither. See ChangeNodeVersion.
            'node_version_change' => $this->node_version_target === null ? null : [
                'target' => $this->node_version_target,
                'status' => $this->node_version_failed_reason === null ? 'switching' : 'failed',
                'reason' => $this->node_version_failed_reason,
                'message' => $this->node_version_failed_reason === null ? null : __('errors/node.change_failed.'.$this->node_version_failed_reason, [
                    'target' => $this->node_version_target,
                    'current' => $this->node_version,
                ]),
                'reference' => $this->node_version_failed_reference,
            ],
            'app_port' => $this->app_port,
            // A second loopback port the site's process listens on (n8n's task broker). Null otherwise,
            // and for an n8n created before it, which keeps n8n's default 5679.
            'internal_port' => $this->internal_port,
            'web_root' => $this->web_root,
            'build_command' => $this->build_command,
            'start_command' => $this->start_command,

            // What a container site runs as. Present for every application so the payload shape does
            // not depend on site type; null on non-containers. The frontend decides the card from
            // `serving_profile`, the same gate the backend uses for the endpoint.
            'image' => $this->image,
            'container_port' => $this->container_port,
            'memory_limit' => $this->memory_limit,
            // Null is meaningful: no CPU quota (a null `memory_limit` means the configured default).
            'cpu_limit' => $this->cpu_limit,
            'docker_network' => $this->docker_network,
            'volume_mounts' => $this->volume_mounts ?? [],
            // Where the container sees the site's own files (`/app` on older sites, `/panel-site`
            // since). A volume cannot mount there, so a form can say why. Null when there is no such
            // mount: not a container, or one run from its own compose file.
            'site_mount_path' => $this->serving_profile === 'docker' && trim((string) $this->compose) === ''
                ? $this->siteMountPath()
                : null,
            // Which stored credential pulls this image: the id for the form and the name for display,
            // never anything from the credential itself. Null means anonymous, not misconfigured.
            'registry_id' => $this->registry_id,
            'registry_name' => $this->whenLoaded('registry', fn (): ?string => $this->registry?->name),
            // The NAMES of the generated credentials, never the values: enough for the UI to render a
            // Credentials section; the values come from their own endpoint.
            'container_secret_keys' => array_keys((array) ($this->docker_secrets ?? [])),
            // Whether anybody confirmed they saved them. False puts the first-run card on the
            // dashboard: a flag about a person, not about the data.
            'credentials_acknowledged' => $this->credentials_seen_at !== null,

            // The last deploy's readiness check: `running`, `restarting`, `exited` or `not_answering`,
            // and for an image with its own HEALTHCHECK `starting` or `unhealthy`. Null when no check
            // has run. A one-click container app reports what Docker says.
            //
            // A stored `running` is asked of Docker first (nothing reports a container that stops after
            // the deploy, and a green badge over a 502 is what this prevents); a stored `exited` is
            // asked the other way round.
            'container_status' => $this->liveContainerStatus(),
            // What is wrong with the app's containers right now, `[{service, state}]`, from the same
            // `docker ps`. A one-click app's optional helpers report here, not in `container_status`;
            // the container that made a status red is listed first. `[]` when nothing is wrong, nothing
            // runs, Docker did not answer, or the site is not a container.
            'health_warnings' => $this->healthWarnings(),
            // Why the last deploy failed, with the container's own last log lines. Cleared by the next
            // deploy that answers, and not shown once an exited container runs again. The log only for
            // someone who may read this site's logs (`app_log`), the same bar as the Logs screen.
            'last_failure' => $this->restarted() ? null : $this->lastFailure($request),

            // Whether this application runs a process of its own (not PHP or static sites), so the UI
            // renders no process controls otherwise.
            'has_process' => app(ProcessSupervisor::class)->runs($this->resource),

            // What keeps the process alive, and how many of it there are. `pm2` means adopted from the
            // old panel and still run by its daemon: the screen offers a switch to a unit.
            'supervisor_mode' => $this->supervisor_mode?->value,
            'process_instances' => $this->process_instances,
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

            // The git account this site was built with has been disconnected: the foreign key is
            // `nullOnDelete`, so the site keeps its repository and branch but loses the credential, and
            // the next deploy would fail. Derived, not stored (see Application::gitAccountMissing()).
            'git_account_missing' => $this->resource->gitAccountMissing(),

            // Deploy-on-push. The secret is shown because the user pastes it into their repository
            // settings and comes back for it. `url` is built here so the frontend never assembles it.
            //
            // The secret only for someone who may deploy (`app_deployment` manage, the bar on POST
            // /deploy): with it and the URL anyone can sign a push and start a deployment.
            'webhook' => [
                'enabled' => (bool) $this->webhook_enabled,
                'provider' => $this->webhook_provider,
                'url' => $this->resource->webhookUrl(),
                'secret' => $request->user()?->canManage('app_deployment') ? $this->webhook_secret : null,
                // True when the panel added the webhook to the repository itself; false means by hand.
                'registered' => $this->webhook_remote_id !== null,
                // Which check this secret gets. `token` is a plaintext shared value (GitLab only, the one
                // thing the panel can generate there); the UI should offer the stronger signing token.
                'verification' => $this->webhook_provider && filled($this->webhook_secret)
                    ? app(WebhookManager::class)
                        ->driver($this->webhook_provider)
                        ->verificationMode((string) $this->webhook_secret)
                    : null,
                'last_delivered_at' => $this->webhook_last_delivered_at?->format('d-m-Y H:i:s'),
                'last_delivered_at_human' => $this->webhook_last_delivered_at?->diffForHumans(),
            ],

            // Cast to an object so an empty settings map serializes as `{}`, not `[]` (json_encode
            // cannot tell them apart). `steps` below is a genuine list and stays `[]`.
            //
            // Without the installer's passwords: new sites keep them in the encrypted `install_secrets`,
            // but a value saved earlier or sent through PUT must not reach anyone who can merely view
            // the site.
            'settings' => (object) array_diff_key(
                $this->settings ?? [],
                array_flip(Application::INSTALL_SECRET_KEYS),
            ),

            // Provisioning progress, so the UI can show which stage it reached.
            'steps' => $this->steps ?? [],
            'failed_step' => $this->failed_step,
            // Why it failed, when the exit status could say; usually null (the step and the reference
            // are the answer). Titled at read time in the viewer's locale.
            'failed_reason' => $this->failed_reason,
            'failed_reason_title' => $this->failed_reason === null
                ? null
                : __('application.failure_reason.'.$this->failed_reason),
            // When the CURRENT run started, restamped on every retry (`created_at` is wrong for elapsed
            // time after a retry). Null on a site never provisioned.
            'provisioning_started_at' => $this->provisioning_started_at?->format('d-m-Y H:i:s'),
            'provisioning_started_at_human' => $this->provisioning_started_at?->diffForHumans(),
            // The last deploy that SUCCEEDED. Not necessarily what is on disk: deploys are in place, so
            // one that fails after its checkout leaves the new commit live. `code_on_disk` answers that.
            'last_commit' => $this->last_commit,
            // `{commit, state, message}`, state `deployed | incomplete | deploying`. Only when
            // `latestCheckout` is loaded, so a list does not query per row. `when`, not `whenLoaded`:
            // that returns null for a loaded-but-empty relation without calling back, which skipped the
            // `last_commit` fallback.
            'code_on_disk' => $this->when($this->resource->relationLoaded('latestCheckout'), fn () => $this->codeOnDisk()),
            // The newest Pull and redeploy of a container site, or null. `in_flight` is the persisted
            // "Redeploying": the site stays `active` while it runs. Eager-loaded on the list.
            'container_redeploy' => $this->resource->serving_profile === 'docker' && $this->resource->latestContainerRedeploy !== null
                ? ApplicationRunResource::make($this->resource->latestContainerRedeploy)->resolve()
                : null,
            'last_deployed_at' => $this->last_deployed_at?->format('d-m-Y H:i:s'),
            'last_deployed_at_human' => $this->last_deployed_at?->diffForHumans(),
            // Quote this to support; the technical detail is in the server-ops log under the same id.
            'reference' => $this->reference,

            // Cached directory size (bytes), refreshed after every deploy. Always present; null means not
            // yet computed. Nothing measures it on a timer (`du` walks every inode); it is set on deploy
            // and on request, so the time it was taken travels with it.
            // The site's TOTAL: document root plus its Docker volumes. The name is historical; for a
            // container site the document root alone holds only a compose file.
            'directory_size_bytes' => $this->directory_size_bytes,
            // The volumes' share of it. Null means the site has no volumes to measure (not the same as 0).
            'volume_size_bytes' => $this->volume_size_bytes,
            'directory_size_measured_at' => $this->directory_size_updated_at?->format('d-m-Y H:i:s'),
            'directory_size_measured_at_human' => $this->directory_size_updated_at?->diffForHumans(),

            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }

    /**
     * The stored readiness status, except that a `running` Docker says is not running reads `exited`,
     * and an `exited` Docker says is running again reads null. Docker unreachable: the stored value.
     */
    private function liveContainerStatus(): ?string
    {
        $stored = $this->container_status;
        $liveness = app(ContainerLiveness::class);

        // The image's own healthcheck, read live: `starting` until it has a verdict, `unhealthy` once
        // it fails (a running container that serves an error page is not Running).
        if (in_array($stored, ['running', 'starting'], true)) {
            $running = $liveness->running($this->resource);

            if ($running === false) {
                return 'exited';
            }

            // The app's main part only: helpers report in `health_warnings`, and a main container
            // stopped next to running helpers is down, not Running.
            return match ($running ? $liveness->verdict($this->resource)['status'] ?? null : null) {
                'unhealthy' => 'unhealthy',
                'restarting' => 'restarting',
                'exited' => 'exited',
                'starting' => 'starting',
                'healthy' => 'running',
                default => $stored,
            };
        }

        if ($this->healthRecovered()) {
            return 'running';
        }

        // A one-click app's status is dropped by a Start or Restart (which check nothing); ask Docker so
        // the API agrees with the panel page. Only an `up` answer: a stopped one was likely stopped on
        // purpose.
        if ($stored === null
            && $this->serving_profile === 'docker'
            && app(ContainerSupervisor::class)->panelRendered($this->resource)
            && $liveness->running($this->resource) === true) {
            return match ($liveness->verdict($this->resource)['status'] ?? null) {
                'unhealthy' => 'unhealthy',
                'restarting' => 'restarting',
                'exited' => 'exited',
                'starting' => 'starting',
                default => 'running',
            };
        }

        // Null, not `running`: a Start since the failed check runs no check, and "up" is not
        // "answering" (same as `forgetReadiness()` after a Start).
        return $this->restarted() ? null : $stored;
    }

    /**
     * @return list<array{service: string, state: string}>
     */
    private function healthWarnings(): array
    {
        if ($this->serving_profile !== 'docker') {
            return [];
        }

        return app(ContainerLiveness::class)->verdict($this->resource)['warnings'] ?? [];
    }

    /**
     * Whether the last check saw the container exit and Docker now has it running again. Without
     * this the badge only moved one way: a fixed and started site kept its red "failed" until the
     * next deploy.
     *
     * Only `exited`: `not_answering` is already running, and `restarting` spends part of every
     * bounce running.
     */
    private function restarted(): bool
    {
        return ($this->container_status === 'exited'
            && app(ContainerLiveness::class)->running($this->resource) === true)
            || $this->healthRecovered();
    }

    /**
     * A deploy failed on the image's healthcheck (unhealthy) and the same check says healthy now:
     * unlike a bare restart, a real verdict that the site was fixed.
     */
    private function healthRecovered(): bool
    {
        return $this->container_status === 'unhealthy'
            && app(ContainerLiveness::class)->health($this->resource) === 'healthy';
    }

    /**
     * `{reason, message, last_line, log, at}`, the message titled in the
     * viewer's locale from the stored reason and its values.
     *
     * @return array<string, mixed>|null
     */
    private function lastFailure(Request $request): ?array
    {
        $failure = $this->last_failure;

        if (! is_array($failure) || ! isset($failure['reason'])) {
            return null;
        }

        $params = (array) ($failure['params'] ?? []);
        $lastLine = (string) ($params['last_line'] ?? '');
        // A failure with no container port to name (a pasted compose file) must not name the panel's
        // host port as the container's.
        $key = $failure['reason'] === 'container_not_answering' && ! isset($params['port'])
            ? 'container_not_answering_no_port'
            : $failure['reason'];
        $message = __('application.container_failure.'.$key, $params);
        $readsLogs = $request->user()?->canView('app_log') ?? false;

        // Output from inside the container (the image's healthcheck) gets the log's bar too.
        $check = (string) ($params['check'] ?? '');

        if ($readsLogs && $check !== '') {
            $message .= ' '.__('application.container_failure.health_check', ['output' => $check]);
        }

        if ($readsLogs && $lastLine !== '') {
            $message .= ' '.__('application.container_failure.last_line', ['line' => $lastLine]);
        }

        return [
            'reason' => (string) $failure['reason'],
            'message' => $message,
            'last_line' => $readsLogs ? $lastLine : null,
            'log' => $readsLogs ? (string) ($failure['log'] ?? '') : null,
            'at' => $failure['at'] ?? null,
        ];
    }
}
