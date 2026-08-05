<?php

namespace App\Models;

use App\Enums\AiBotPolicy;
use App\Enums\ApplicationStatus;
use App\Enums\DomainType;
use App\Enums\WafCategory;
use App\Enums\WafMode;
use App\Services\Applications\SiteTypeManager;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A site the panel manages. Provisioning (files, vhost, clone) lands in later
 * phases — a record here only means "the user asked for this".
 */
#[Fillable([
    'system_user_id', 'name', 'domain', 'site_type', 'serving_profile', 'status',
    'php_version', 'node_version', 'app_port', 'rendering_type', 'web_root',
    'build_command', 'deploy_script', 'start_command',
    'git_account_id', 'repository', 'repository_url', 'branch', 'settings',
    'steps', 'failed_step', 'reference', 'last_commit', 'last_deployed_at',
    'webhook_enabled', 'webhook_provider', 'webhook_identifier', 'webhook_secret',
    'webhook_last_delivered_at',
])]
class Application extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'isolated_at' => 'datetime',
            'disabled_at' => 'datetime',
            'basic_auth_enabled' => 'boolean',
            'ai_bot_policy' => AiBotPolicy::class,
            'waf_enabled' => 'boolean',
            'waf_mode' => WafMode::class,
            'waf_categories' => 'array',
            'status' => ApplicationStatus::class,
            'settings' => 'array',
            'steps' => 'array',
            'last_deployed_at' => 'datetime',
            'webhook_enabled' => 'boolean',
            // Encrypted at rest: this is the one value that lets an
            // unauthenticated request start a deploy.
            'webhook_secret' => 'encrypted',
            'webhook_last_delivered_at' => 'datetime',
        ];
    }

    public function systemUser(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class);
    }

    /**
     * Null for a public repository — those need no stored credential.
     */
    public function gitAccount(): BelongsTo
    {
        return $this->belongsTo(GitAccount::class);
    }

    /**
     * Every name this application answers to.
     *
     * `$this->domain` remains the primary one — the vhost filename and the log
     * filenames are built from it — and is kept in step with whichever row is
     * marked primary here.
     */
    public function domains(): HasMany
    {
        return $this->hasMany(ApplicationDomain::class);
    }

    /**
     * The names the web server should serve this site under: the primary and
     * its aliases, in a stable order with the primary first. Redirects get
     * their own server block and are not part of this.
     *
     * Falls back to the mirrored column when an application has no rows yet,
     * so a site provisioned before this feature still renders a valid config.
     *
     * @return array<int, string>
     */
    public function serverNames(): array
    {
        $names = $this->domains
            ->filter(fn (ApplicationDomain $domain) => $domain->servesContent())
            ->sortBy(fn (ApplicationDomain $domain) => $domain->type === DomainType::Primary ? 0 : 1)
            ->pluck('domain')
            ->all();

        return $names !== [] ? $names : array_filter([$this->domain]);
    }

    /**
     * The app-sidebar items this site actually supports, by permission name.
     *
     * The second of the two filters behind an application's sidebar — the
     * first being what the user has been granted. Answered by the site type,
     * so a WordPress site does not offer a Deployment screen it has no
     * repository for, and a static site does not offer PHP settings it has no
     * PHP for.
     *
     * An unknown type (a row from before a type was removed) supports nothing
     * beyond the basics rather than everything: an empty answer shows a thin
     * sidebar, a permissive one shows screens that then fail.
     *
     * @return array<int, string>
     */
    public function phpSettings(): HasOne
    {
        return $this->hasOne(ApplicationPhpSettings::class);
    }

    public function wafRules(): HasMany
    {
        return $this->hasMany(ApplicationWafRule::class);
    }

    public function features(): array
    {
        return app(SiteTypeManager::class)->find($this->site_type)?->features()
            ?? ['app_dashboard', 'app_domain', 'app_log'];
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /**
     * Which of the six 8G categories are active. Null (never touched this
     * screen) means all of them — turning the firewall on should protect
     * everything by default, not start from an empty set.
     *
     * @return array<int, string>
     */
    public function wafActiveCategories(): array
    {
        return $this->waf_categories ?? WafCategory::values();
    }

    /**
     * Newest first wherever this is read — the list only ever answers "what
     * happened recently".
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    /**
     * The names that can go on a certificate, primary first.
     *
     * A subset of `serverNames()`: redirects are included, because a redirect
     * from `http://old` to `https://new` is only reachable if `old` also
     * answers on HTTPS — a browser that has ever seen HSTS will refuse the
     * plaintext hop and the redirect never runs. Test domains are excluded,
     * since nip.io shares one weekly issuance limit with the whole internet.
     *
     * @return array<int, string>
     */
    public function certifiableDomains(): array
    {
        return $this->domains
            ->filter(fn (ApplicationDomain $domain) => $domain->certifiable())
            ->sortBy(fn (ApplicationDomain $domain) => $domain->type === DomainType::Primary ? 0 : 1)
            ->pluck('domain')
            ->values()
            ->all();
    }
}
