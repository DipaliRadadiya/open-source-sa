<?php

namespace App\Models;

use App\Enums\AiBotPolicy;
use App\Enums\ApplicationStatus;
use App\Enums\DomainType;
use App\Enums\WafCategory;
use App\Enums\WafMode;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\WebServers\WebServerManager;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A site the panel manages. Provisioning (files, vhost, clone) lands in later
 * phases — a record here only means "the user asked for this".
 */
#[Fillable([
    'system_user_id', 'production_application_id', 'cloned_from_application_id', 'name', 'domain', 'site_type', 'serving_profile', 'status',
    'php_version', 'node_version', 'app_port', 'rendering_type', 'web_root',
    'build_command', 'deploy_script', 'start_command', 'package_manager',
    'git_account_id', 'repository', 'repository_url', 'branch', 'settings',
    'steps', 'failed_step', 'provisioning_started_at', 'reference', 'last_commit', 'last_deployed_at', 'directory_size_bytes', 'directory_size_updated_at',
    'current_release_id', 'previous_release_path',
    'webhook_enabled', 'webhook_provider', 'webhook_identifier', 'webhook_secret',
    'webhook_last_delivered_at',
    'fail2ban_enabled',
    'fail2ban_jail_name', 'fail2ban_jail_content', 'fail2ban_filter_content',
])]
class Application extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'isolated_at' => 'datetime',
            'provisioning_started_at' => 'datetime',
            'disabled_at' => 'datetime',
            'basic_auth_enabled' => 'boolean',
            'ai_bot_policy' => AiBotPolicy::class,
            'waf_enabled' => 'boolean',
            'waf_mode' => WafMode::class,
            'waf_categories' => 'array',
            'fail2ban_enabled' => 'boolean',
            'fail2ban_jail_name' => 'string',
            'fail2ban_jail_content' => 'string',
            'fail2ban_filter_content' => 'string',
            'status' => ApplicationStatus::class,
            'settings' => 'array',
            'steps' => 'array',
            'last_deployed_at' => 'datetime',
            'directory_size_bytes' => 'integer',
            'directory_size_updated_at' => 'datetime',
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

    /**
     * A stable, unique, filesystem-safe slug from the name — the key for the
     * web-server config filename. Suffixes `-2`, `-3`, … on collision.
     *
     * Stored rather than derived at read time so the panel can always address
     * the file it wrote, including when the name has since changed and the
     * rename is halfway through. Same shape as {@see Cronjob::uniqueSlug()},
     * which names `/etc/cron.d` files the same way.
     */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'application';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * The same collision handling for the name itself, for the names the panel
     * derives rather than the user types — "<source> (Clone)" is the same
     * string every time, and `name` is unique, so a second clone of one site
     * would otherwise be a constraint violation surfacing as a 500.
     *
     * Only for derived names. A name the user typed is checked by validation
     * instead: silently renaming what someone chose is worse than telling them
     * it is taken.
     */
    public static function uniqueName(string $name): string
    {
        $candidate = $name;
        $suffix = 2;

        while (static::query()->where('name', $candidate)->exists()) {
            $candidate = $name.' '.$suffix++;
        }

        return $candidate;
    }

    /** Per-site additions to, and exemptions from, the built-in AI bot list. */
    public function botRules(): HasMany
    {
        return $this->hasMany(ApplicationBotRule::class);
    }

    /** Set only on a staging site — the production site it was cloned from. */
    public function production(): BelongsTo
    {
        return $this->belongsTo(self::class, 'production_application_id');
    }

    /** One staging site per production application, enforced at create time. */
    public function staging(): HasOne
    {
        return $this->hasOne(self::class, 'production_application_id');
    }

    public function isStaging(): bool
    {
        return $this->production_application_id !== null;
    }

    /** Set only on a clone — the application it was cloned from. Informational only. */
    public function clonedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cloned_from_application_id');
    }

    public function features(): array
    {
        $features = app(SiteTypeManager::class)->find($this->site_type)?->features()
            ?? ['app_dashboard', 'app_domain', 'app_log'];

        // The 8G Firewall is the one app feature whose availability is a
        // *server* fact rather than a site-type one: it is rendered into the
        // vhost, and the OpenLiteSpeed templates carry no WAF rules. Hidden
        // here rather than shown-and-refused, which also 404s its endpoints
        // through CheckPermission — same "hide rather than grey" reasoning as
        // the site-type filter itself. There is nothing the user could do to
        // turn it on, so a disabled row would only be noise.
        //
        // Fails OPEN. driver() throws when the server has no capability row
        // yet — a freshly provisioned box, or a test that never wrote one —
        // and this runs on every sidebar and every application route. Hiding
        // a working screen because the web server is momentarily unknown is
        // worse than showing one the manager will refuse anyway; Waf8GManager
        // still guards the write.
        $wafSupported = rescue(
            fn (): bool => app(WebServerManager::class)->driver()->supportsWaf(),
            true,
            report: false,
        );

        if (! $wafSupported) {
            $features = array_values(array_diff($features, ['app_firewall']));
        }

        return $features;
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
    public function cronjobs(): HasMany
    {
        return $this->hasMany(Cronjob::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    /**
     * The backup configuration for this site, if it has one.
     *
     * Null is a real answer here — an unconfigured site is exactly what the
     * cross-application list is for.
     */
    public function backupTarget(): HasOne
    {
        return $this->hasOne(BackupTarget::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    /**
     * The newest backup, however it ended.
     *
     * Not "the newest successful one": a list whose whole job is to show
     * whether a site is protected must show the failure, not skip past it to
     * the last time things worked.
     */
    public function latestBackup(): HasOne
    {
        return $this->hasOne(Backup::class)->latestOfMany();
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

    /**
     * The scheme this site can actually be reached on right now.
     *
     * Not a preference and not a default: `https` is a claim that the site can
     * complete TLS with a certificate covering its primary hostname. A site
     * without one still owns its name on port 443 only to reject stale HSTS or
     * HTTPS-first traffic safely; that isolation listener is deliberately not
     * a usable public URL and must never make this method advertise HTTPS.
     *
     * `force_https` is deliberately not consulted. That flag decides whether
     * plain HTTP *redirects*, not whether HTTPS answers; a site with a
     * certificate and the redirect turned off still serves TLS perfectly well.
     */
    public function scheme(): string
    {
        return $this->certificate?->servable()
            && $this->certificate->covers((string) $this->domain)
                ? 'https'
                : 'http';
    }

    /**
     * This site's public URL, optionally with a path appended.
     *
     * Exists so that nothing else builds `'https://'.$domain` by hand. Eleven
     * places did, across the installers, the phpMyAdmin SSO redirect and the
     * frontend — every one of them wrong on a site with no certificate, and
     * the installers' copies worse than wrong because they are written into
     * the application's own configuration, where WordPress and Moodle turn
     * them into a redirect the panel cannot see or undo.
     */
    public function url(string $path = ''): string
    {
        return $this->scheme().'://'.$this->domain.$path;
    }

    /**
     * This site's own directory on disk: `{home}/{slug}`.
     *
     * Everything the site owns hangs off here — `public_html`, `.env`,
     * `releases/`, `.panel/`, its logs. Named by **slug, never domain**: a
     * domain can be changed or pointed elsewhere, and a path that moves when
     * it does orphans every file already written under the old one.
     *
     * This existed as five hand-built copies of the same concatenation, and
     * the sixth — OpenLiteSpeed's vhost root — used `domain`, which put the
     * document root outside the directory `restrained 1` confines the vhost
     * to. One method so the next caller cannot invent a seventh.
     */
    public function rootPath(): string
    {
        $home = rtrim((string) $this->systemUser?->home_path, '/');

        // A row from before the slug column would otherwise produce
        // `{home}/`, which resolves to the system user's home — the directory
        // every one of its sites would then share.
        return $this->slug ? "{$home}/{$this->slug}" : $home;
    }

    /**
     * The directory the web server serves: `{home}/{slug}/public_html/{web_root}`.
     *
     * `public_html` is always the base; `web_root` is appended inside it, so a
     * site can serve from a subdirectory without anything escaping the site's
     * own tree.
     *
     * The provisioner has computed this since the feature was built and still
     * owns the call sites; this is the same arithmetic on the model, so that a
     * resource or a form request can ask an application where it lives without
     * resolving a service to find out.
     */
    public function documentRoot(): string
    {
        $webRoot = trim((string) $this->web_root, '/');
        $base = $this->rootPath().'/public_html';

        return $webRoot === '' ? $base : "{$base}/{$webRoot}";
    }

    /**
     * The directory the application's own command-line tools run from.
     *
     * **Not always the document root**, which is why this is a separate method
     * and not a comment on one. Three cases, and the difference is invisible in
     * fifteen of the seventeen site types:
     *
     *  - Most marketplace applications unpack into the served directory, so
     *    `wp-cron.php`, `admin/cli/cron.php` and `cron.php` sit in the document
     *    root and the two answers coincide.
     *  - **Craft and Statamic build a project around the served directory** —
     *    `web/`, `public/` — and their `craft` and `please` binaries sit one
     *    level above it. That is exactly where their installers `cd` to run
     *    them.
     *  - A git site has its repository cloned *into* the document root, so
     *    whatever the checkout contains is there, `web_root` notwithstanding.
     *
     * Derived from the type's {@see SiteType::fixedWebRoot()} rather than by
     * pattern-matching on `web_root`, because that method is already the
     * codebase's answer to "does this type's installer build a layout around
     * the web root" — and a second, independent guess at the same question is
     * a second thing to get wrong later.
     *
     * This is the value `{path}` expands to in a cron command preset. A cron
     * job pointed at the wrong one of these directories does not fail — it runs
     * a file that is not there and reports nothing.
     */
    public function codePath(): string
    {
        $documentRoot = $this->documentRoot();

        $fixed = app(SiteTypeManager::class)->find((string) $this->site_type)?->fixedWebRoot();

        return $fixed === null ? $documentRoot : dirname($documentRoot);
    }

    /**
     * The HTTP Basic Auth credential file.
     *
     * Under the app root, deliberately **not** under the document root. The
     * file has to be 0644 for the web server's worker to read it at request
     * time, so inside the served directory the only thing standing between a
     * bcrypt hash and the public internet is the vhost's dotfile deny rule —
     * which OpenLiteSpeed did not apply to `.panel`. Above the webroot it is
     * unreachable over HTTP no matter what any vhost says.
     *
     * It also stops the path moving when `web_root` changes, which used to
     * need the credential file relocating in step or protection failed.
     *
     * Both the manager that writes it and the driver that points the vhost at
     * it read this, because they each built the string themselves and would
     * otherwise have to be changed in lockstep forever.
     */
    public function basicAuthPath(): string
    {
        return $this->panelPath().'/.htpasswd';
    }

    /**
     * Where the panel keeps its own bookkeeping for this site.
     *
     * Above the document root, never inside it. Everything that lands here is
     * something the panel wrote and no visitor should ever fetch: the Basic
     * Auth credential, PHP sessions, the PHP error log, the WAF detect log,
     * and the database dump taken before a staging push. Inside the served
     * directory the only thing hiding any of it is the vhost's dotfile deny
     * rule — one line, per web server, that OpenLiteSpeed did not apply to
     * this directory at all.
     */
    public function panelPath(): string
    {
        return $this->rootPath().'/.panel';
    }
}
