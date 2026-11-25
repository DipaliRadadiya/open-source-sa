<?php

namespace App\Models;

use App\Enums\AiBotPolicy;
use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DomainType;
use App\Enums\SupervisorMode;
use App\Enums\WafCategory;
use App\Enums\WafMode;
use App\Exceptions\Server\Application\SystemUserMissingException;
use App\Rules\ContainerMountPath;
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
 * A site the panel manages. A row only means "the user asked for this"; provisioning runs later.
 */
#[Fillable([
    'system_user_id', 'production_application_id', 'cloned_from_application_id', 'name', 'domain', 'site_type', 'serving_profile', 'status',
    'php_version', 'node_version', 'node_version_target', 'node_version_failed_reason', 'node_version_failed_reference',
    'app_port', 'internal_port', 'rendering_type', 'web_root',
    // Containers: `container_port` is inside the container, `app_port` is the host loopback port
    // the web server proxies to. Mixing them up publishes a container on a port another app holds.
    'image', 'registry_id', 'container_port', 'memory_limit', 'cpu_limit', 'compose', 'docker_network', 'volume_mounts', 'site_mount_path', 'docker_secrets', 'credentials_seen_at', 'container_status', 'last_failure',
    'build_command', 'deploy_script', 'start_command', 'package_manager', 'process_instances', 'supervisor_mode', 'pm2_process_name',
    'git_account_id', 'repository', 'repository_url', 'branch', 'settings', 'install_secrets', 'nginx_location',
    'steps', 'failed_step', 'failed_reason', 'provisioning_started_at', 'reference', 'last_commit', 'last_deployed_at', 'directory_size_bytes', 'volume_size_bytes', 'directory_size_updated_at',
    'current_release_id', 'previous_release_path',
    'webhook_enabled', 'webhook_provider', 'webhook_identifier', 'webhook_secret', 'webhook_remote_id',
    'webhook_last_delivered_at',
    'fail2ban_enabled',
    'fail2ban_jail_name', 'fail2ban_jail_content', 'fail2ban_filter_content',
])]
class Application extends Model
{
    use HasFactory;

    /**
     * Fields a site type asks for as a `password`. Stored in the encrypted `install_secrets`, never
     * in `settings`, and never returned by the API.
     *
     * A fixed list because building a type's fields runs server commands and this is read on every
     * response. InstallSecretsTest fails if a type adds a password field that is missing here.
     */
    public const INSTALL_SECRET_KEYS = ['admin_password', 'mailer_password'];

    /**
     * Where a container site's create-time env vars wait, encrypted, until provisioning writes them
     * to its env file. Never accepted from `settings`, never sent back.
     */
    public const CONTAINER_ENV_SECRET = 'container_env';

    /**
     * Settings the installer records about what it installed. Read-only to the API: a client that
     * could write `php_range` could move a PrestaShop shop onto a PHP its release dies on.
     */
    public const INSTALLER_RECORDED_KEYS = ['php_range', 'prestashop_version'];

    /**
     * Never serialized: the installer's passwords are for the installer.
     *
     * @var list<string>
     */
    protected $hidden = ['install_secrets'];

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
            'volume_mounts' => 'array',
            // Holds the container's last log lines, where a first boot prints connection strings.
            // Encrypted; the API shows it to `app_log` viewers only.
            'last_failure' => 'encrypted:array',
            // Live database credentials of a one-click container app; encrypted because they grant
            // access.
            'docker_secrets' => 'encrypted:array',
            'credentials_seen_at' => 'datetime',
            'fail2ban_enabled' => 'boolean',
            'fail2ban_jail_name' => 'string',
            'fail2ban_jail_content' => 'string',
            'fail2ban_filter_content' => 'string',
            'status' => ApplicationStatus::class,
            'supervisor_mode' => SupervisorMode::class,
            'settings' => 'array',
            // Installer passwords, held only until the install succeeds. See installSettings().
            'install_secrets' => 'encrypted:array',
            'steps' => 'array',
            'last_deployed_at' => 'datetime',
            'directory_size_bytes' => 'integer',
            'volume_size_bytes' => 'integer',
            'directory_size_updated_at' => 'datetime',
            'webhook_enabled' => 'boolean',
            // Encrypted: the one value that lets an unauthenticated request start a deploy.
            'webhook_secret' => 'encrypted',
            'webhook_last_delivered_at' => 'datetime',
        ];
    }

    /**
     * What an installer reads: `settings` plus the passwords it was created with.
     *
     * The passwords live apart in `install_secrets` because `settings` is plain JSON returned by
     * the API. They are held only until the install succeeds; ProvisionApplication then clears
     * them.
     *
     * @return array<string, mixed>
     */
    public function installSettings(): array
    {
        return array_merge($this->settings ?? [], $this->install_secrets ?? []);
    }

    public function systemUser(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class);
    }

    /** Null for a public repository: no stored credential is needed. */
    public function gitAccount(): BelongsTo
    {
        return $this->belongsTo(GitAccount::class);
    }

    /** Null for a public image (every one-click app and most sites). Mirrors `gitAccount()`. */
    public function registry(): BelongsTo
    {
        return $this->belongsTo(Registry::class);
    }

    /**
     * Deployed from a git account that is no longer connected.
     *
     * An account-sourced site has a `repository` and no `repository_url`, so a public-URL site is
     * never mistaken for it. Such a site cannot deploy (its remote would be `""`); the resource
     * reports it and every deploy path refuses on it.
     */
    public function gitAccountMissing(): bool
    {
        return $this->site_type === 'git'
            && $this->git_account_id === null
            && $this->repository !== null
            && $this->repository_url === null;
    }

    /**
     * Every name this application answers to. `$this->domain` stays the primary (vhost and log
     * filenames use it) and follows whichever row is marked primary.
     */
    public function domains(): HasMany
    {
        return $this->hasMany(ApplicationDomain::class);
    }

    /**
     * Names the web server serves this site under: primary first, then aliases. Redirects get their
     * own server block and are not included.
     *
     * Falls back to the mirrored `domain` column when there are no rows (sites provisioned
     * earlier).
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
     * The app-sidebar items this site supports, by permission name (the second filter, after what
     * the user was granted). An unknown site type supports only the basics: a thin sidebar beats
     * screens that fail.
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
     * The longest a name may be before the paths derived from it stop fitting.
     *
     * The slug (see uniqueSlug()) names a web server config file; filenames cap at 255 bytes and
     * the panel appends `-tls.conf` (9). 240 = 255 − 9 − 6, the last being room for the `-2`
     * collision suffix. A cap, not truncation: silently shortening a name would show the user
     * something they did not choose. The OpenLiteSpeed driver truncates only where it must (socket
     * paths cap at 108 bytes).
     */
    public const MAX_NAME_LENGTH = 240;

    /** How many names `uniqueSlug()` asks the disk about before giving up (see the loop). */
    public const SLUG_DISK_ATTEMPTS = 10;

    /**
     * A stable, unique, filesystem-safe slug from the name, the key for the web server config
     * filename. Suffixes `-2`, `-3`, … on collision.
     *
     * Stored so the panel can always address the file it wrote, even after the name has changed.
     * Same shape as {@see Cronjob::uniqueSlug()}.
     */
    public static function uniqueSlug(string $name, ?int $ignoreId = null, ?callable $alsoTaken = null): string
    {
        $base = Str::slug($name) ?: 'application';
        $slug = $base;
        $suffix = 2;

        // The table check ends by itself (finite rows); the disk predicate does not. One that
        // answers "taken" to everything spins forever (`Process::fake()` with no handler does
        // exactly that). So the disk is asked a bounded number of times, then ignored; the slug
        // stays unique in the table.
        $attempts = 0;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()
            // Second meaning of "taken": a file already at that path belongs to someone else. Create/rename
            // refuse ({@see \App\Rules\AvailableSiteName}); clone and staging pass a predicate and step over
            // the name; sync discovery passes none (its slug is the folder it found).
            || ($alsoTaken !== null && $attempts < self::SLUG_DISK_ATTEMPTS && $alsoTaken($slug))
        ) {
            $slug = $base.'-'.$suffix++;
            $attempts++;
        }

        return $slug;
    }

    /**
     * Same collision handling for panel-derived names ("<source> (Clone)" repeats and `name` is
     * unique, so a second clone would be a 500). Not for names the user typed: validation reports
     * those instead of silently renaming.
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

        // 8G Firewall is a server fact, not a site-type one: the OpenLiteSpeed templates carry no WAF
        // rules, so it is hidden rather than shown-and-refused. Fails OPEN: driver() throws when the
        // server has no capability row yet, and Waf8GManager still guards the write.
        $wafSupported = rescue(
            fn (): bool => app(WebServerManager::class)->driver()->supportsWaf(),
            true,
            report: false,
        );

        if (! $wafSupported) {
            $features = array_values(array_diff($features, ['app_firewall']));
        }

        // A git site's rendering type (static/csr/ssr, see {@see \App\Services\Applications\ServingProfile::resolve()})
        // decides whether it serves PHP, but `GitSiteType` still reports `php`. Read from the row, where the
        // user's choice is recorded, so a site serving no PHP is not offered the PHP screen.
        if ($this->serving_profile !== 'php') {
            $features = array_values(array_diff($features, ['app_php']));
        }

        return $features;
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /**
     * Which of the six 8G categories are active. Null (screen never touched) means all of them.
     *
     * @return array<int, string>
     */
    public function wafActiveCategories(): array
    {
        return $this->waf_categories ?? WafCategory::values();
    }

    /** Newest first wherever this is read: the list only answers "what happened recently". */
    public function cronjobs(): HasMany
    {
        return $this->hasMany(Cronjob::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * Where a provider delivers this site's push events, or null before deploy-on-push is set up.
     * Built in one place so the URL shown to the user matches the one registered with the provider.
     */
    public function webhookUrl(): ?string
    {
        return $this->webhook_identifier
            ? url("/api/webhooks/deploy/{$this->webhook_identifier}")
            : null;
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ApplicationRun::class);
    }

    /**
     * The newest Pull and redeploy of a container site: the app screen reads it to show
     * "Redeploying" across a reload, and how the last one ended.
     */
    public function latestContainerRedeploy(): HasOne
    {
        return $this->hasOne(ApplicationRun::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('kind', ApplicationRun::CONTAINER_REDEPLOY),
        );
    }

    /**
     * The newest deploy that got as far as checking out a commit: the code on disk now, whether or
     * not that deploy then succeeded.
     *
     * Deploys are in place, so one failing after the checkout leaves new code live while
     * `last_commit` (written on success only) still names the old one. See {@see codeOnDisk()}.
     */
    public function latestCheckout(): HasOne
    {
        return $this->hasOne(Deployment::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->whereNotNull('commit_hash'),
        );
    }

    /**
     * Which commit is on disk and whether its deploy finished: `deployed`, `incomplete` (failed
     * after the checkout) or `deploying`. Falls back to `last_commit` for a site with no recorded
     * checkout (deployed before deployments were recorded, or a clone). `message` is set for
     * `incomplete` only.
     *
     * @return array{commit: ?string, state: ?string, message: ?string}
     */
    public function codeOnDisk(): array
    {
        $checkout = $this->latestCheckout;

        if ($checkout === null) {
            return ['commit' => $this->last_commit, 'state' => $this->last_commit !== null ? 'deployed' : null, 'message' => null];
        }

        $state = match ($checkout->status) {
            DeploymentStatus::Succeeded => 'deployed',
            DeploymentStatus::Failed => 'incomplete',
            default => 'deploying',
        };

        return [
            'commit' => $checkout->commit_hash,
            'state' => $state,
            'message' => $state === 'incomplete'
                ? __('application.code_on_disk.incomplete', ['commit' => substr((string) $checkout->commit_hash, 0, 7)])
                : null,
        ];
    }

    /**
     * The databases treated as part of this application: backups dump them; staging, cloning and
     * restoring copy them.
     *
     * `hasMany`, but attaching is capped at one by `UpdateDatabaseApplicationRequest` because
     * staging and cloning take the first row and cannot yet say which they mean.
     */
    public function databases(): HasMany
    {
        return $this->hasMany(Database::class);
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    /** The backup configuration for this site; null (unconfigured) is a real answer. */
    public function backupTarget(): HasOne
    {
        return $this->hasOne(BackupTarget::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    /**
     * The newest backup, however it ended. Not "newest successful": a list showing whether a site
     * is protected must show the failure.
     */
    public function latestBackup(): HasOne
    {
        return $this->hasOne(Backup::class)->latestOfMany();
    }

    /**
     * Every name this site answers to, primary included, to recognise a certificate v7 left for it
     * (CertificateFiles::v7Pair()).
     *
     * @return array<int, string>
     */
    public function domainNames(): array
    {
        return $this->domains->pluck('domain')->push($this->domain)->filter()->unique()->values()->all();
    }

    /**
     * The names that can go on a certificate, primary first.
     *
     * Redirects are included: an `http://old` → `https://new` redirect only works if `old` also
     * answers on HTTPS (HSTS browsers refuse the plaintext hop). Test domains are excluded, since
     * nip.io shares one weekly issuance limit with the whole internet.
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
     * `https` is a claim that the site can complete TLS with a certificate covering its primary
     * hostname. The 443 isolation listener a certificate-less site gets only rejects stale HSTS or
     * HTTPS-first traffic; it is not a usable public URL and must never make this advertise HTTPS.
     * `force_https` is deliberately not consulted: it decides whether HTTP redirects, not whether
     * HTTPS answers.
     */
    public function scheme(): string
    {
        return $this->certificate?->servable()
            && $this->certificate->covers((string) $this->domain)
                ? 'https'
                : 'http';
    }

    /**
     * This site's public URL, optionally with a path appended. Use it instead of building
     * `'https://'.$domain` by hand: that is wrong on a site with no certificate, and installers
     * write such URLs into the app's own configuration, where the panel cannot undo them.
     */
    public function url(string $path = ''): string
    {
        return $this->scheme().'://'.$this->domain.$path;
    }

    /**
     * This site's own directory: `{home}/{slug}`. Everything the site owns hangs off it
     * (`public_html`, `.env`, `releases/`, `.panel/`, logs).
     *
     * Named by slug, never domain: a domain can change, and a path that moves with it orphans the
     * files already written. One method, so no caller builds its own copy (one used `domain` and
     * put the OpenLiteSpeed document root outside the directory `restrained 1` confines the vhost
     * to).
     */
    public function rootPath(): string
    {
        $home = rtrim((string) $this->systemUser?->home_path, '/');

        // No home, no path. Falling through to `/{slug}` once made "delete with files" run `rm -rf`
        // as root on a top-level directory (a site named `etc`).
        if ($home === '') {
            throw new SystemUserMissingException((string) $this->name);
        }

        // A row from before the slug column would give `{home}/`, the home all its sites would
        // share.
        return $this->slug ? "{$home}/{$this->slug}" : $home;
    }

    /**
     * The directory the web server serves: `{home}/{slug}/public_html/{web_root}`.
     *
     * `public_html` is the fixed base every site's files live under; `web_root` only selects what
     * is served *inside* it and must not move it (a git checkout would move with it).
     */
    /**
     * Where a simple-mode container sees this site's own directory. Null means `/app`, what sites
     * created before the column existed got, so their compose file renders unchanged. New sites
     * store {@see ContainerMountPath::SITE_MOUNT}, a path no image ships content in (`/app` hid the
     * program of many popular images).
     */
    public function siteMountPath(): string
    {
        return $this->site_mount_path ?: ContainerMountPath::LEGACY_SITE_MOUNT;
    }

    /**
     * Does a stored compose file decide what this container runs?
     *
     * True for a 1-Click Recipe app, a pasted file, and a simple site whose generated file was
     * taken over on the Compose File page. The supervisor renders the stored text and ignores the
     * structured port, network and volume fields, same test as `contents()`.
     */
    public function hasOwnCompose(): bool
    {
        return trim((string) $this->compose) !== '';
    }

    public function publicHtmlPath(): string
    {
        return $this->rootPath().'/public_html';
    }

    public function documentRoot(): string
    {
        $webRoot = trim((string) $this->web_root, '/');
        $base = $this->publicHtmlPath();

        return $webRoot === '' ? $base : "{$base}/{$webRoot}";
    }

    /**
     * The directory the application's own command-line tools run from. Not always the document
     * root:
     *
     *  - Most marketplace apps unpack into the served directory, so both coincide.
     *  - Craft and Statamic build a project around the served directory (`web/`, `public/`); their
     *    `craft`/`please` binaries sit one level above it, where their installers run them.
     *  - A git site's checkout always lands at `public_html`, whatever `web_root` says.
     *
     * The first two come from {@see SiteType::fixedWebRoot()}, the git case from {@see
     * SiteType::method()} ("how did the code get here"). This is what `{path}` expands to in a cron
     * command preset; a cron job in the wrong directory runs a missing file and reports nothing.
     */
    public function codePath(): string
    {
        $type = app(SiteTypeManager::class)->find((string) $this->site_type);

        if ($type?->method() === 'git') {
            return $this->publicHtmlPath();
        }

        $documentRoot = $this->documentRoot();

        return $type?->fixedWebRoot() === null ? $documentRoot : dirname($documentRoot);
    }

    /**
     * The HTTP Basic Auth credential file: `{site}/conf/{web server}/.htpasswd`, where v7 keeps it
     * (folder named after the web server: nginx, apache, openlitespeed). A server moved from v7
     * already has its credential there.
     *
     * Under the app root, not the document root: the file must be 0644 for the web server worker,
     * and inside the served directory only the vhost's dotfile deny rule (which OpenLiteSpeed did
     * not apply to `.panel`) would hide the hash. The manager that writes it and the driver that
     * reads it both call this method.
     */
    public function basicAuthPath(): string
    {
        return $this->siteConfPath().'/.htpasswd';
    }

    /**
     * v7's per-site web server folder, `{site}/conf/{web server}`: the vhost loads every `*.conf`
     * in it (bot blocker, 8G, Apache force-HTTPS and users' own rules), so a site moved from v7
     * keeps every rule it had. Root's, see SiteConfFolder.
     */
    public function siteConfPath(): string
    {
        return $this->rootPath().'/conf/'.app(WebServerManager::class)->driver()->name();
    }

    /**
     * Where the panel keeps its own bookkeeping for this site.
     *
     * Above the document root, never inside it: nothing here may be fetchable, and the vhost's
     * dotfile deny rule is one line per web server (OpenLiteSpeed did not apply it to this
     * directory).
     */
    public function panelPath(): string
    {
        return $this->rootPath().'/.panel';
    }

    /**
     * The application's own `.env`: beside the code, but never inside the served directory.
     *
     * The two coincide wherever a web root separates code from served files. They conflict only
     * when the code root is the served directory, and safety wins. nginx denies dotfiles, but the
     * codebase does not rely on that (see `panelPath()`).
     *
     * On the model, not in `ApplicationEnvironment`: provisioning needs the same answer, and
     * `ApplicationEnvironment` depends on `ApplicationProvisioner`, so the service cannot be the
     * source without a container cycle.
     */
    public function envPath(): string
    {
        $code = $this->codePath();

        return $code === $this->documentRoot() && $this->servesFiles()
            ? $this->rootPath().'/.env'
            : $code.'/.env';
    }

    /**
     * Does this application's document root hand out files, or is it proxied?
     *
     * Used by {@see envPath()}. A Node app sits behind a reverse proxy: nothing under its document
     * root is fetchable by path, so its `.env` stays beside the code where the systemd unit loads
     * it. `php` and `static` serve the directory's files, so the rule holds (Apache's dotfile rule
     * is a `DirectoryMatch` and does not cover a `.env` file).
     */
    public function servesFiles(): bool
    {
        return $this->serving_profile !== 'node';
    }

    /**
     * Every log this site produces, in one directory (web server access/error logs, process logs,
     * PHP error and WAF detect logs), so "where are my logs" does not depend on the web server.
     *
     * Above the document root: an access log names every visitor and URL. Not `.panel`, which is
     * deliberately unreadable by the site's owner while a log is what they most need to read.
     * {@see ApplicationLogDirectory} for the ownership that makes both true.
     */
    public function logsPath(): string
    {
        return $this->rootPath().'/logs';
    }

    /**
     * The root-owned directory of extra web server rules this site's vhost includes.
     * {@see config('server.site_rules_root')}
     *
     * Named like the vhost file (slug, or domain on a row from before slugs) so the two always
     * agree and the directory never has to follow a rename.
     */
    public function siteRulesPath(): string
    {
        return rtrim((string) config('server.site_rules_root'), '/').'/'.($this->slug ?: $this->domain);
    }

    /**
     * Where the WAF writes what it *would* have blocked, in detect mode. Defined once because the
     * vhost template data and the Logs screen each built it and drifted, so detect mode showed an
     * empty log however much it matched.
     */
    public function wafDetectLogPath(): string
    {
        return $this->logsPath().'/waf-detect.log';
    }
}
