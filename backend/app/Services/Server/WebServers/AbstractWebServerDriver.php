<?php

namespace App\Services\Server\WebServers;

use App\Contracts\WebServerDriver;
use App\Enums\AiBotPolicy;
use App\Enums\CertificateType;
use App\Enums\DomainType;
use App\Enums\WafMode;
use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Models\ApplicationWafRule;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\ApplicationLogDirectory;
use App\Services\Server\Applications\PanelDirectory;
use App\Services\Server\Applications\SiteRootLock;
use App\Services\Server\Certificates\CertbotClient;
use App\Services\Server\Certificates\CertificateFiles;
use App\Services\Server\ManagedFile;
use App\Services\Server\Php\PoolManager;
use App\Services\Server\ServerAddresses;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Facades\View;

abstract class AbstractWebServerDriver implements WebServerDriver
{
    public function __construct(
        protected ServerOps $serverOps,
        protected ManagedFile $files,
        protected CertificateFiles $certificateFiles,
        protected ApplicationLogDirectory $logDirectory,
        protected CertbotClient $certbot,
    ) {}

    /**
     * The site's files in sites-available, each symlinked from sites-enabled —
     * the same layout install.sh itself uses for the panel's own vhost. A
     * driver whose configuration is not files in such a pair (OpenLiteSpeed)
     * overrides this.
     *
     * Two files on nginx and Apache, as v7 writes them ({@see configFiles()}):
     * a file that should not exist is removed, link first, so the web server
     * never reads a link to nothing.
     */
    public function apply(Application $application, string $documentRoot): ServerOpsResult
    {
        $fallback = $this->ensureTlsFallback($application);

        if ($fallback->failed()) {
            return $fallback;
        }

        $this->ensureDirectories($application);

        $context = ['feature' => 'application', 'application' => $application->id];
        $result = new ServerOpsResult(true, 'config-applied');

        foreach ($this->configFiles($application, $documentRoot) as $path => $contents) {
            if ($contents === null) {
                $this->files->delete($this->enabledPathFor($path), $context + ['op' => 'remove_config']);
                $this->files->delete($path, $context + ['op' => 'remove_config']);

                continue;
            }

            $written = $this->files->put($path, $contents, $context + ['op' => 'write_config']);

            if ($written->failed()) {
                return $written;
            }

            $result = $this->link($path, $context + ['op' => 'enable_config']);

            if ($result->failed()) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * Every config file this site has, by path in sites-available, with what it
     * should hold — or null for a file that must not exist.
     *
     * **v7's layout (v8 follows it, step B1).** v7 writes a site as
     * `{name}.conf` for plain HTTP and a second file for HTTPS:
     * `{name}-le-ssl.conf` with a Let's Encrypt certificate, `{name}-ssl.conf`
     * with any other. A server moved from v7 already has those files, and v8
     * writing everything into `{name}.conf` left v7's SSL file beside it: two
     * server blocks for the same name on 443. Writing the same two files under
     * the same names replaces v7's in place.
     *
     * A driver that does not split (OpenLiteSpeed, whose layout already is
     * v7's) has one file.
     *
     * @return array<string, ?string>
     */
    public function configFiles(Application $application, string $documentRoot): array
    {
        if (! $this->splitsSsl()) {
            return [$this->configPath($application) => $this->renderConfig($application, $documentRoot)];
        }

        $files = [$this->configPath($application) => $this->renderSection($application, $documentRoot, 'main')];
        $active = $this->activeSslPath($application);

        foreach ($this->sslConfigPaths($application) as $path) {
            $files[$path] = $path === $active ? $this->renderSection($application, $documentRoot, 'ssl') : null;
        }

        return $files;
    }

    /**
     * Both names the HTTPS file can have — v7's — so the one that is not in
     * use can be removed when the certificate changes type.
     *
     * @return array{0: string, 1: string} [Let's Encrypt, any other]
     */
    public function sslConfigPaths(Application $application): array
    {
        $directory = rtrim((string) config("server.web_server_drivers.{$this->name()}.sites_available_dir"), '/');
        $name = $this->fileName($application);

        return ["{$directory}/{$name}-le-ssl.conf", "{$directory}/{$name}-ssl.conf"];
    }

    /**
     * The sites-enabled link for a file in sites-available.
     */
    public function enabledPathFor(string $availablePath): string
    {
        $directory = rtrim((string) config("server.web_server_drivers.{$this->name()}.sites_dir"), '/');

        return $directory.'/'.basename($availablePath);
    }

    /**
     * Make a written file live. A symlink from sites-enabled on nginx and
     * Apache; nothing to do for a driver that does not split.
     *
     * @param  array<string, mixed>  $context
     */
    public function link(string $availablePath, array $context = []): ServerOpsResult
    {
        if (! $this->splitsSsl()) {
            return new ServerOpsResult(true, 'no-link-required');
        }

        return $this->files->symlink($availablePath, $this->enabledPathFor($availablePath), $context);
    }

    /**
     * Whether this web server keeps a site's HTTPS in a file of its own (v7's
     * nginx and Apache layout).
     */
    protected function splitsSsl(): bool
    {
        return true;
    }

    private function activeSslPath(Application $application): ?string
    {
        $certificate = $application->certificate;

        if (! $certificate?->servable()) {
            return null;
        }

        [$letsEncrypt, $other] = $this->sslConfigPaths($application);

        return $certificate->type === CertificateType::LetsEncrypt ? $letsEncrypt : $other;
    }

    /**
     * A no-certificate site must still own its hostname on port 443. Browsers
     * retain HSTS and HTTPS-first state after uninstall; without a reject
     * vhost, that request falls through to another application's first/default
     * TLS vhost. Apache and OpenLiteSpeed need a harmless shared key pair to
     * reject it without serving another tenant.
     */
    protected function ensureTlsFallback(Application $application): ServerOpsResult
    {
        if ($application->certificate?->servable() || $this->name() === 'nginx') {
            return new ServerOpsResult(true, 'tls-fallback-not-required');
        }

        return $this->certificateFiles->ensureFallback();
    }

    /**
     * The shared ACME webroot, before a vhost that names it goes on disk.
     *
     * Every template declares a context/location for
     * `{challenge_root}/.well-known/acme-challenge`, and until now the only
     * thing that created that directory was `IssueCertificate` — so on a
     * server where no certificate had ever been requested, every site's config
     * pointed at a directory that was not there.
     *
     * nginx and Apache resolve a location per request, so the cost was a
     * missing directory nobody noticed until issuance failed. **OpenLiteSpeed
     * resolves a context's location when the configuration is loaded**, which
     * is a different class of problem: install.sh hit exactly this and its
     * `ensure_ols_context_paths()` says so — *"neither does the acme-challenge
     * directory certbot has not yet had a reason to create"* — creating the
     * panel's own before writing the panel's vhost. Sites never got the same
     * treatment.
     *
     * Cheap and idempotent (`install -d`), and deliberately unchecked: this
     * must not be able to stop a site being written. A challenge root that
     * cannot be created is certbot's problem to report at issuance, where the
     * user is asking for a certificate, not here.
     */
    protected function ensureChallengeRoot(): void
    {
        $this->certbot->ensureChallengeRoot();
    }

    /**
     * Every directory the rendered config is about to name.
     *
     * Public, and called by anything that writes a vhost — not only
     * `apply()`. `sites:resync` renders and writes the same file directly, so
     * that it can skip a site whose config has not changed, and it used to
     * write configs without any of this. On OpenLiteSpeed a context's
     * `location` is resolved when the config loads rather than per request, so
     * a resync that rewrote a vhost naming a challenge root nothing had
     * created failed the config test and rolled that site back — and the
     * advice "deploy, then resync" quietly did not repair the thing it was
     * given for.
     *
     * All three are best-effort in the same way `apply()` always treated
     * them: a directory that cannot be created is reported by whatever needs
     * it, not by refusing to write a site's configuration.
     */
    public function ensureDirectories(Application $application): bool
    {
        $this->ensurePanelDirectory($application);
        $this->ensureChallengeRoot();
        $this->ensureSiteRules($application);

        // The vhost names `logs/access.log`, and a web server refuses to start
        // when a log file's directory does not exist. A missing directory here
        // would fail `nginx -t` on every site and read as a bad template
        // rather than as an absent folder.
        //
        // The only step here whose effect a running web server cannot see
        // until it restarts, so it is the only one that answers. The two
        // above create directories, which every later config test reads fresh.
        return $this->logDirectory->ensure($application);
    }

    /**
     * Nothing, by default: nginx and Apache open a site's access log in the
     * root master process and hand the descriptor to a worker, so no
     * unprivileged account ever needs to reach the log directory.
     *
     * Stated here rather than left to each driver so that adding a fourth web
     * server inherits the answer that grants nothing, and has to say so
     * deliberately if its workers open their own logs.
     */
    /**
     * nginx and Apache workers run as the account PHP-FPM pool sockets are
     * already handed to ({@see PoolManager}) —
     * `www-data` on the Debian packages install.sh uses. The same setting, so
     * the two cannot name different accounts.
     */
    public function siteReaderUser(): ?string
    {
        $user = (string) config('server.web_server_user', 'www-data');

        return $user === '' ? null : $user;
    }

    public function logWriterUser(): ?string
    {
        return null;
    }

    public function catchAllConfig(): ?array
    {
        $path = (string) config("server.web_server_drivers.{$this->name()}.catch_all");

        if ($path === '' || ! View::exists("server.vhosts.{$this->name()}._catch-all")) {
            return null;
        }

        $enabledDir = (string) config("server.web_server_drivers.{$this->name()}.catch_all_enabled_dir");

        return [
            'path' => $path,
            'enabled' => $enabledDir === '' ? null : rtrim($enabledDir, '/').'/'.basename($path),
            'contents' => View::make("server.vhosts.{$this->name()}._catch-all", $this->catchAllViewData())->render(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function catchAllViewData(): array
    {
        return ['tlsFallback' => $this->certificateFiles->fallbackPaths()];
    }

    /**
     * The rendered config can name files inside `.panel/` — the WAF detect
     * log, the Basic Auth credential — and nginx refuses to start when a log
     * directory does not exist. Nothing created this at provision time, so
     * turning WAF detect mode on for a site that had never had its web root
     * moved wrote a config that could not pass `nginx -t`, and the failure
     * looked like a bad ruleset rather than a missing directory.
     */
    protected function ensurePanelDirectory(Application $application): void
    {
        // `.panel` is an entry of the site root, which is immutable once the
        // site is set up ({@see SiteRootLock}); creating it on a site that
        // never had one needs the flag lifted, an existing one needs nothing.
        // Resolved here rather than injected so every driver's constructor
        // does not have to learn about it.
        app(SiteRootLock::class)->ensureDirectory(
            $application,
            $application->panelPath(),
            ['feature' => 'application', 'op' => 'ensure_panel_dir', 'application' => $application->id],
        );

        // And root's again, with anything planted in it removed. Here because
        // `sites:resync` comes through this method on every deploy: that is
        // how the servers where `.panel` had been handed to the site user
        // repair themselves. See PanelDirectory::secure().
        app(PanelDirectory::class)->secure($application);
    }

    /**
     * The site's extra-rules directory, which its vhost includes.
     *
     * Created before the vhost is written because the include must not point
     * at nothing on OpenLiteSpeed. Root's, 0755, and never emptied here — the
     * files inside are an addon's, and re-rendering the vhost must leave them
     * exactly as they were. Removed with the site by ApplicationArtifacts,
     * never by `remove()`: that also runs for a resync's legacy-config cleanup
     * (on a copy with the slug blanked) and its rollback, and neither may take
     * a site's rules with it. {@see config('server.site_rules_root')}
     */
    protected function ensureSiteRules(Application $application): void
    {
        $context = ['feature' => 'application', 'op' => 'ensure_site_rules', 'application' => $application->id];

        $this->serverOps->run(['mkdir', '-p', $application->siteRulesPath()], $context);
        $this->serverOps->run(['chmod', '0755', $application->siteRulesPath()], $context);
    }

    /**
     * Removes both the sites-enabled symlink and the sites-available file it
     * pointed to, so a re-provision starts from nothing rather than an
     * available file nothing links to. Both calls are idempotent (`rm -f`),
     * so this runs unconditionally rather than stopping at the first failure.
     */
    public function remove(Application $application): ServerOpsResult
    {
        $context = ['feature' => 'application', 'op' => 'remove_config', 'application' => $application->id];
        $first = null;
        $last = new ServerOpsResult(true, 'nothing-to-remove');

        // Every file the site can have — the HTTPS file under either of its
        // names too — each link before its file.
        foreach ([$this->configPath($application), ...($this->splitsSsl() ? $this->sslConfigPaths($application) : [])] as $path) {
            foreach ([$this->enabledPathFor($path), $path] as $target) {
                $result = $this->files->delete($target, $context);
                $first ??= $result->failed() ? $result : null;
                $last = $result;
            }
        }

        return $first ?? $last;
    }

    /**
     * The real file — sites-available. This is what gets rendered, tested via
     * its symlink, and what disable()/enable() overwrite the contents of.
     */
    public function configPath(Application $application): string
    {
        $directory = rtrim((string) config("server.web_server_drivers.{$this->name()}.sites_available_dir"), '/');

        // Named after the application, not its domain. A domain is mutable and
        // was never unique, so two sites could claim one and silently overwrite
        // each other's vhost, and changing a domain orphaned the old file under
        // a name nothing could address any more. The slug is unique, is a
        // filename by construction, and belongs to the site rather than to one
        // of its names.
        return "{$directory}/{$this->fileName($application)}.conf";
    }

    /**
     * The symlink — sites-enabled. This is what the web server actually reads
     * via its `include sites-enabled/*` directive; configPath() alone is
     * invisible to it until this exists.
     */
    protected function enabledPath(Application $application): string
    {
        $directory = rtrim((string) config("server.web_server_drivers.{$this->name()}.sites_dir"), '/');

        return "{$directory}/{$this->fileName($application)}.conf";
    }

    /**
     * Falls back to the domain for a row that predates the slug column, so a
     * site provisioned before this still resolves to the file it actually has
     * on disk rather than to a name nothing was ever written under.
     */
    protected function fileName(Application $application): string
    {
        return (string) ($application->slug ?: $application->domain);
    }

    /**
     * Serving profiles that share a vhost, and which one they share.
     *
     * A container and a Node application are the same thing to a web server:
     * something listening on a loopback port that nginx reverse-proxies to,
     * WebSocket upgrade included. The `node` template is already entirely
     * runtime-agnostic — it proxies to `127.0.0.1:{{ $appPort }}` and never
     * asks what is behind it.
     *
     * A map rather than a `docker.blade.php` that includes the other, because
     * the duplicate would be a second file to keep in step and the whole point
     * is that there is nothing different to say. The template is selected by
     * profile NAME, though, so without this a container aborts with a bare 500
     * from `View::exists` — which is how this was found.
     *
     * @var array<string, string>
     */
    private const VHOST_PROFILE_ALIASES = [
        'docker' => 'node',
    ];

    /**
     * Everything the site's files hold, in one string — what tests and any
     * reader that wants "the site's configuration" look at. Written to disk
     * it is {@see configFiles()}, one file per part.
     */
    public function renderConfig(Application $application, string $documentRoot): string
    {
        if (! $this->splitsSsl()) {
            return $this->renderSection($application, $documentRoot, null);
        }

        return implode("\n", array_filter($this->configFiles($application, $documentRoot), fn (?string $contents) => $contents !== null));
    }

    /**
     * One file's worth: `main` (plain HTTP, and the no-certificate reject on
     * 443) or `ssl` (HTTPS). Null for a template that is not split.
     */
    protected function renderSection(Application $application, string $documentRoot, ?string $section): string
    {
        $profile = (string) $application->serving_profile;
        $profile = self::VHOST_PROFILE_ALIASES[$profile] ?? $profile;

        $view = "server.vhosts.{$this->name()}.{$profile}";

        abort_unless(View::exists($view), 500);

        return View::make($view, $this->viewData($application, $documentRoot) + ['section' => $section])->render();
    }

    /**
     * The site's `post_max_size` in bytes, as the web server should enforce it.
     *
     * Reads the site's own settings where it has them and the shared defaults
     * where it does not — the same `effective()` the pool file is rendered
     * from, so the two numbers come from one source and a site cannot end up
     * with a web server and a PHP that disagree.
     *
     * A floor of 1 MB, because `post_max_size = 0` is legal PHP and means
     * "no limit": handing 0 to nginx means the same thing there, but handing it
     * to Apache's `LimitRequestBody` also means unlimited while OpenLiteSpeed
     * reads 0 as *reject every body*. One value that means three things is how
     * a site ends up rejecting all uploads on one web server and none on
     * another, so a real number goes to all three.
     */
    protected function maxBodySizeBytes(Application $application): int
    {
        $settings = $application->phpSettings ?? new ApplicationPhpSettings;

        $bytes = ApplicationPhpSettings::toBytes((string) $settings->effective()['post_max_size']);

        return max($bytes, 1024 * 1024);
    }

    /**
     * What a vhost template is given. A driver whose syntax needs more than
     * this adds to it.
     *
     * @return array<string, mixed>
     */
    protected function viewData(Application $application, string $documentRoot): array
    {
        $siteType = app(SiteTypeManager::class)->find((string) $application->site_type);

        return [
            'application' => $application,
            'domain' => $application->domain,
            'canonicalUrl' => $application->url(),
            // Log filenames, not server_name: named after the app the same
            // way the vhost and pool files already are (see fileName()) so
            // all three agree on one identifier, and a domain change doesn't
            // orphan the log history under a name nothing points to anymore.
            'logName' => $this->fileName($application),
            // Where those files go. Handed to the template rather than built
            // inside it, so the vhost and `logPaths()` cannot drift — they did
            // not, but only because two separate places happened to spell the
            // same string, and a driver whose template disagreed with its own
            // `logPaths()` would point fail2ban and the Logs screen at a file
            // nothing writes. {@see Application::logsPath()}
            'logDir' => $application->logsPath(),
            // Every name the site answers to, primary first. Templates used to
            // hardcode `www.{domain}` alongside the primary; that guess is now
            // a row in application_domains, backfilled for existing sites so
            // what they serve is unchanged.
            'serverNames' => $application->serverNames(),
            // Redirects get their own server block — they serve nothing, they
            // send a 301 somewhere else, and mixing them into the main block
            // would serve the same content under both names instead.
            'redirects' => $application->domains
                ->filter(fn ($domain) => $domain->type === DomainType::Redirect)
                ->values(),
            // Only a certificate with files behind it. A pending or failed one
            // is deliberately hidden from the template: pointing a server block
            // at a path that is not there fails the config test and takes a
            // working site down over a certificate it never had.
            'certificate' => $application->certificate?->servable() ? $application->certificate : null,
            // Never borrow a real site's certificate when a no-certificate
            // hostname reaches 443. Apache/OLS require a key pair before they
            // can reject the request; this reserved-name pair identifies no
            // user application and is generated lazily on brownfield boxes.
            'tlsFallback' => $this->certificateFiles->fallbackPaths(),
            // Paths the site type ships `.htaccess` deny rules for. Apache
            // reads those itself; nginx and OpenLiteSpeed render these.
            // A disabled site: its vhost serves the shared "unavailable" page,
            // and serves it as 503 — a 200 told monitors and crawlers the site
            // was up.
            'disabled' => $application->disabled_at !== null,
            'deniedPaths' => $siteType?->deniedPaths() ?? [],
            // Directories with their own front controller, which Apache gets
            // from the application's own `.htaccess`.
            'subdirectoryFrontControllers' => $siteType?->subdirectoryFrontControllers() ?? [],
            // /.well-known routing and file types the application sets in its
            // own .htaccess — Nextcloud's CalDAV/CardDAV discovery and .mjs.
            'wellKnown' => $siteType?->wellKnownRoutes() ?? ['redirects' => [], 'fallback' => null],
            'mimeTypes' => $siteType?->mimeTypes() ?? [],
            // Password protection forces it too (junior re-test #9): with the
            // redirect off, plain http asked for the site password and took it
            // in clear. Nothing to redirect to without a certificate, so an
            // http-only site keeps working, and the API says it is unencrypted
            // (`basic_auth_unencrypted`).
            'forceHttps' => $forceHttps = $application->scheme() === 'https'
                && ($application->certificate?->force_https || $application->basic_auth_enabled),
            // Names the certificate does not cover, while HTTPS is forced.
            // Sending one to https://<that name> lands the visitor on a
            // certificate error, so they go to the primary instead — which a
            // forced-HTTPS certificate always covers, since forcing needs the
            // site's own scheme to be https. Apache already sends every name
            // to the primary; nginx and OLS keep each covered name's own host.
            'uncoveredNames' => $forceHttps
                ? array_values(array_filter(
                    $application->serverNames(),
                    fn (string $name): bool => ! $application->certificate->covers($name),
                ))
                : [],
            // The shared ACME webroot, aliased into every profile. Per-site
            // document roots cannot work for node and proxy sites — they serve
            // nothing from disk, so there is nowhere for certbot to drop the
            // challenge token.
            'challengeRoot' => rtrim((string) config('server.certificates.challenge_root'), '/'),
            'documentRoot' => $documentRoot,
            // Included by the vhost, never rendered into it — see ensureSiteRules().
            'siteRules' => $application->siteRulesPath(),
            // The largest request body this site accepts, in bytes.
            //
            // Every web server has its own default for this and nothing here
            // used to set any of them, which on nginx means 1 MB — its
            // built-in default — no matter what the site's PHP said. So the
            // panel wrote `upload_max_filesize = 64M` into the pool, the PHP
            // Settings screen showed 64 MB, and WordPress answered "413
            // Request Entity Too Large" at one megabyte. Raising the PHP
            // values changed nothing, because nginx rejects the request before
            // PHP is reached: reported on 2026-09-08 by a user who had already
            // set both to 512M.
            //
            // Derived from `post_max_size` rather than `upload_max_filesize`,
            // because that is the same measurement the web servers make — the
            // whole request body, including the multipart boundaries and the
            // other fields, not the one file inside it.
            //
            // Resolved here so all three templates read one number and cannot
            // disagree. Apache and OpenLiteSpeed were never broken by this
            // (their defaults are effectively unlimited), but a limit that
            // exists on one server and not the others is the same site
            // behaving differently on different boxes.
            'maxBodySize' => $this->maxBodySizeBytes($application),
            'phpVersion' => $application->php_version ?: config('server.default_php_version'),
            // Where PHP actually is for this site. An isolated site has its own
            // pool running as its own user; everything else still shares the
            // server-wide pool, which is what every site did before pools
            // existed. Resolved here so no template has to know the rule.
            'phpSocket' => app(PoolManager::class)->socketFor($application),
            // The OS account the site runs as. nginx and Apache reach PHP
            // through a pool that already knows this; OLS spawns the process
            // itself and has to be told.
            'user' => $application->systemUser?->username,
            // Where a reverse proxy sends traffic, and a name for the backend
            // that is unique per application — OpenLiteSpeed declares external
            // applications by name, and two sites sharing one would have the
            // second quietly overwrite the first.
            'appPort' => $application->app_port,
            'appName' => 'sv-app-'.$application->id,
            // Null when off, so every template gates the whole block behind
            // one `@if` instead of re-checking `basic_auth_enabled` itself.
            // The path comes from the model rather than being rebuilt here:
            // this copy and BasicAuthManager's had to agree, and when the
            // file moved above the webroot only one of them would have.
            'basicAuth' => $application->basic_auth_enabled ? [
                'realm' => 'sv-app-'.$application->id,
                'htpasswdPath' => $application->basicAuthPath(),
                // Paths the application requests from itself (WordPress's
                // wp-cron.php, bug #75), let through without a password only
                // from the server's own addresses; null when the type has none.
                'serverOnly' => ($serverOnly = $siteType?->serverOnlyPaths() ?? []) === [] ? null : [
                    'paths' => $serverOnly,
                    'addresses' => app(ServerAddresses::class)->local(),
                ],
            ] : null,
            // A single regex-ready, alternation-joined string — already
            // escaped — or null when the policy blocks nothing. Resolved
            // here, once, so no template re-implements "which bots does this
            // policy block" against `config/ai_bots.php` itself.
            'botBlock' => $this->botBlockPattern($application),
            'waf' => $this->wafViewData($application, $documentRoot),
        ];
    }

    /**
     * Whether this driver can actually enforce the 8G Firewall.
     *
     * Asked rather than assumed, because the alternative is what shipped: the
     * shared ruleset writer matched nginx and apache and fell through to a
     * silent `return` for anything else, and no OpenLiteSpeed vhost template
     * references the rules at all. The API answered 200, the record said
     * `waf_enabled: true`, and not one request was inspected — a panel
     * claiming protection it is not providing, which is worse than one that
     * says no.
     */
    public function supportsWaf(): bool
    {
        return true;
    }

    /**
     * A WAF exception or custom rule as a case-insensitive *literal* match,
     * ready to sit inside a double-quoted regex in this web server's config.
     *
     * nginx: `preg_quote` makes it literal; nginx's quoted strings then turn
     * `\\` into `\` and `\"` into `"`, so a backslash the user typed is
     * doubled and a quote escaped. Control characters never get here — the
     * request refuses them.
     */
    public function wafPattern(string $value): string
    {
        return str_replace(['\\\\', '"'], ['\\\\\\\\', '\\"'], preg_quote($value));
    }

    /**
     * Null unless the firewall is on and actually has something to check —
     * zero categories and zero custom rules means nothing would ever be
     * blocked, so the template renders no block at all rather than an
     * `<RequireAny>`/`if` chain with nothing inside it.
     *
     * @return array{mode: string, categories: array<int, string>, exceptions: array<int, string>, customRules: array<int, string>, detectLogPath: string, logFormat: string}|null
     */
    private function wafViewData(Application $application, string $documentRoot): ?array
    {
        if (! $application->waf_enabled) {
            return null;
        }

        $categories = $application->wafActiveCategories();
        // `wafRules` lazy-loads from the database if not already set on the
        // model — which also means a caller can pre-set it in memory (see
        // Waf8GManager::apply()) to render against not-yet-saved rules
        // without a second query silently overwriting them.
        $customRules = $application->wafRules->where('type', 'block')->pluck('value')->all();

        if ($categories === [] && $customRules === []) {
            return null;
        }

        // Short ones are skipped, not just refused on save: one stored before
        // the limit would otherwise go on switching the firewall off.
        $exceptions = $application->wafRules->where('type', 'exception')->pluck('value')
            ->filter(fn (string $value): bool => mb_strlen(trim($value)) >= ApplicationWafRule::EXCEPTION_MIN_LENGTH)
            ->values()->all();

        return [
            'mode' => $application->waf_mode instanceof WafMode ? $application->waf_mode->value : (string) $application->waf_mode,
            'categories' => $categories,
            // Escaped here, for this web server's config syntax, and printed
            // raw by the templates. They went through Blade's `{{ }}`, which
            // HTML-encodes: `page=1&x` became `page=1&amp;x` in the config and
            // never matched (found live, 2026-09-30).
            'exceptions' => array_map(fn (string $value): string => $this->wafPattern($value), $exceptions),
            'customRules' => array_map(fn (string $value): string => $this->wafPattern($value), $customRules),
            'detectLogPath' => $application->wafDetectLogPath(),
            // nginx: the name of the site's own log_format for that file. A
            // name is server-wide in nginx, so it carries the site's id.
            'logFormat' => 'panel_waf_'.$application->id,
        ];
    }

    /**
     * The policy's built-in list, plus this site's own additions, minus its
     * own exemptions.
     *
     * Exemptions are subtracted last and case-insensitively, so "allow this
     * one" wins over both the built-in list and a contradictory custom block
     * — a rule that says allow and a rule that says block can only have one
     * safe resolution, and the one that keeps traffic flowing is it.
     *
     * `botRules` lazy-loads if not already set, which also lets a caller
     * pre-set it in memory (see BotBlockerManager::apply()) to render against
     * not-yet-saved rules without a second query overwriting them.
     */
    private function botBlockPattern(Application $application): ?string
    {
        $bots = $application->ai_bot_policy instanceof AiBotPolicy
            ? $application->ai_bot_policy->blockedBots()
            : [];

        $rules = $application->botRules;

        $bots = array_merge($bots, $rules->where('type', 'block')->pluck('value')->all());

        $allowed = $rules->where('type', 'allow')
            ->map(fn ($rule) => mb_strtolower((string) $rule->value))
            ->all();

        $bots = array_values(array_filter(
            array_unique($bots),
            fn (string $bot) => ! in_array(mb_strtolower($bot), $allowed, true),
        ));

        if ($bots === []) {
            return null;
        }

        return implode('|', array_map(fn (string $bot) => preg_quote($bot, '/'), $bots));
    }

    public function test(): ServerOpsResult
    {
        return $this->serverOps->run(
            $this->testCommand(),
            ['feature' => 'application', 'op' => 'config_test', 'web_server' => $this->name()],
        );
    }

    public function reload(): ServerOpsResult
    {
        return $this->serverOps->run(
            $this->reloadCommand(),
            ['feature' => 'application', 'op' => 'reload', 'web_server' => $this->name()],
        );
    }

    /**
     * The reload command as something else can run.
     *
     * Exposed for certbot's post-renewal hook, which is a shell script run by
     * certbot's own timer rather than by the panel. Without it renewal
     * half-works: a new certificate lands on disk and the web server keeps
     * serving the old one from memory until something unrelated reloads it,
     * which surfaces weeks later as an expired certificate on a healthy site.
     *
     * @return array<int, string>
     */
    public function reloadCommandForHook(): array
    {
        return $this->reloadCommand();
    }

    /**
     * @return array<int, string>
     */
    abstract protected function testCommand(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function reloadCommand(): array;
}
